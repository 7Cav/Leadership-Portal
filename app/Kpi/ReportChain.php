<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use ErrorException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use TypeError;
use UnexpectedValueException;

/**
 * The roll-up read model behind 5.8's command navigator: every unit under a root, each with its
 * report status and KPIs for one Cav week, as a tree built from the materialized path and
 * `parent_id` (AD-18). It composes the shared pieces rather than re-deriving anything (AD-21):
 * a sent unit shows its frozen `ReportSnapshot`, an unsent leaf its live `KpiService` figures, an
 * unsent non-leaf `RollupKpis`' sum of its children's sent snapshots (AD-13), and the delta comes
 * from `KpiDelta` against the prior week's sent snapshot, as `WeeklyReportKpis` computes it.
 *
 * Five queries whatever the size of the chain — the subtree, this week's and last week's reports,
 * and the section KPIs — so a battalion or the whole regiment reads in one pass. Callers pass
 * only a root the ScopeResolver permits (AD-8); the subtree under it is in scope by construction.
 */
final class ReportChain
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly KpiService $kpis,
    ) {}

    /**
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     * @throws UnexpectedValueException when a sent snapshot in the chain cannot be read back.
     */
    public function build(Unit $root, CarbonInterface $weekStart): ChainNode
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $units = $this->units->subtree($root);
        $ids = $units->modelKeys();

        return $this->node(
            $root,
            $units->groupBy('parent_id'),
            $this->reports->forUnits($ids, $weekStart),
            $this->reports->forUnits($ids, $weekStart->subWeek()),
            $this->kpis->forSectionsWithin($root, $weekStart),
            $weekStart,
        );
    }

    /**
     * @param  Collection<int|string, Collection<int, Unit>>  $byParent
     * @param  Collection<int, WeeklyReport>  $reports
     * @param  Collection<int, WeeklyReport>  $priorReports
     * @param  Collection<int, UnitKpis>  $liveSections
     */
    private function node(Unit $unit, Collection $byParent, Collection $reports, Collection $priorReports, Collection $liveSections, CarbonImmutable $weekStart): ChainNode
    {
        $children = $byParent->get($unit->id, collect());
        $rollup = $children->isEmpty() ? null : RollupKpis::fromReports($children, $reports, $weekStart);
        $report = $reports->get($unit->id);
        $childNodes = $children
            ->map(fn (Unit $child) => $this->node($child, $byParent, $reports, $priorReports, $liveSections, $weekStart))
            ->values()
            ->all();

        if ($report?->status === WeeklyReportStatus::Sent) {
            $snapshot = self::snapshot($report);

            return new ChainNode($unit, $report, $snapshot->kpis, $snapshot->delta, $rollup, $childNodes);
        }

        // Unsent: a non-leaf shows what its report would freeze, its children's sent snapshots;
        // a leaf its live KPIs, `none()` when nobody is assigned to it (`forSectionsWithin`).
        $kpis = $rollup?->kpis ?? $liveSections->get($unit->id, UnitKpis::none());
        $prior = $priorReports->get($unit->id);
        $delta = $prior?->status === WeeklyReportStatus::Sent ? KpiDelta::between($kpis, self::snapshot($prior)->kpis) : null;

        return new ChainNode($unit, $report, $kpis, $delta, $rollup, $childNodes);
    }

    /**
     * A sent snapshot that does not read back is a corrupt record: it fails loudly with the
     * unit-week named, as `RollupKpis` does, rather than showing a plausible zero.
     */
    private static function snapshot(WeeklyReport $report): ReportSnapshot
    {
        try {
            return ReportSnapshot::fromArray($report->kpis);
        } catch (InvalidArgumentException|TypeError|ErrorException $e) {
            throw new UnexpectedValueException(
                "The sent report of unit {$report->unit_id} for {$report->week_start->toDateString()} has an unreadable snapshot: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
