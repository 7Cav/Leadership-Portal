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

/**
 * The roll-up read model behind 5.8's command navigator: every unit under a root, each with its
 * report status and KPIs for one Cav week, as a tree built from the materialized path and
 * `parent_id` (AD-18). It composes the shared pieces rather than re-deriving anything (AD-21):
 * a sent unit shows its frozen `ReportSnapshot`, an unsent leaf its live `KpiService` figures, an
 * unsent non-leaf `RollupKpis`' sum of its children's sent snapshots (AD-13), and the delta comes
 * from `KpiDelta` against the prior week's sent snapshot, as `WeeklyReportKpis` computes it.
 *
 * A line unit with no troopers in it or under it is not in the chain (#442, the one test in
 * {@see Headcount::withoutEmptyLineUnits()}): it is not drawn, not summed and holds nobody up. The
 * root is always drawn, as the unit the caller asked for.
 *
 * Seven queries whatever the size of the chain — the subtree, which of it has troopers (two), this
 * week's and last week's reports, and the section KPIs — so a battalion or the whole regiment reads
 * in one pass. Callers pass only a root the ScopeResolver permits (AD-8); the subtree under it is
 * in scope by construction.
 *
 * A sent snapshot that does not read back is a corrupt record. It is reported once per build and
 * shown where it is used as unknown, never as a plausible zero, while the rest of the chain still
 * renders: its own unit's KPIs are null, an unsent parent above it has no roll-up to sum (null
 * KPIs, no roll-up), and an unsent unit whose prior week is unreadable has no delta.
 */
final class ReportChain
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly KpiService $kpis,
        private readonly Headcount $headcount,
    ) {}

    /**
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function build(Unit $root, CarbonInterface $weekStart): ChainNode
    {
        $weekStart = WeekOf::requireStart($weekStart);
        // The reporting chain is the line: groups (staff, ELOA, Reservists, New Recruits) have no report (2026-10-24),
        // and neither has a line unit nobody is placed in or under (#442).
        $units = $this->headcount->withoutEmptyLineUnits($this->units->lineSubtree($root))
            ->reject(fn (Unit $unit) => $unit->id === $root->id)
            ->prepend($root);
        $ids = $units->modelKeys();
        $reports = $this->reports->forUnits($ids, $weekStart);
        $priorReports = $this->reports->forUnits($ids, $weekStart->subWeek());

        return $this->node(
            $root,
            $units->groupBy('parent_id'),
            $reports,
            self::frozen($reports),
            self::frozen($priorReports),
            $this->kpis->forSectionsWithin($root, $weekStart),
            $weekStart,
        );
    }

    /**
     * @param  Collection<int|string, Collection<int, Unit>>  $byParent
     * @param  Collection<int, WeeklyReport>  $reports
     * @param  array<int, ReportSnapshot|null>  $frozen  this week's sent snapshots by unit, null when unreadable
     * @param  array<int, ReportSnapshot|null>  $priorFrozen  last week's, the same way
     * @param  Collection<int, UnitKpis>  $liveSections
     */
    private function node(Unit $unit, Collection $byParent, Collection $reports, array $frozen, array $priorFrozen, Collection $liveSections, CarbonImmutable $weekStart): ChainNode
    {
        $children = $byParent->get($unit->id, collect());
        // A child whose sent snapshot cannot be read leaves the sum unknown, not smaller: no roll-up.
        $unreadableChild = $children->contains(fn (Unit $child) => array_key_exists($child->id, $frozen) && $frozen[$child->id] === null);
        $rollup = $children->isEmpty() || $unreadableChild ? null : RollupKpis::fromReports($children, $reports, $weekStart);
        $report = $reports->get($unit->id);
        $childNodes = $children
            ->map(fn (Unit $child) => $this->node($child, $byParent, $reports, $frozen, $priorFrozen, $liveSections, $weekStart))
            ->values()
            ->all();

        if ($report?->status === WeeklyReportStatus::Sent) {
            $snapshot = $frozen[$unit->id];

            return new ChainNode($unit, $report, $snapshot?->kpis, $snapshot?->delta, $rollup, $childNodes);
        }

        // Unsent: a non-leaf shows what its report would freeze, its children's sent snapshots;
        // a leaf its live KPIs, `none()` when nobody is assigned to it (`forSectionsWithin`).
        $kpis = $children->isEmpty() ? $liveSections->get($unit->id, UnitKpis::none()) : $rollup?->kpis;
        $prior = $priorFrozen[$unit->id] ?? null;
        $delta = $kpis !== null && $prior !== null ? KpiDelta::between($kpis, $prior->kpis) : null;

        return new ChainNode($unit, $report, $kpis, $delta, $rollup, $childNodes);
    }

    /**
     * Each sent report's snapshot read back once, by unit id; null for one that does not read back.
     * That is reported here, once per build, so the corruption is not silent while one bad row no
     * longer takes the page down for everyone above it.
     *
     * @param  Collection<int, WeeklyReport>  $reports
     * @return array<int, ReportSnapshot|null>
     */
    private static function frozen(Collection $reports): array
    {
        $frozen = [];

        foreach ($reports as $report) {
            if ($report->status !== WeeklyReportStatus::Sent) {
                continue;
            }

            try {
                $frozen[$report->unit_id] = ReportSnapshot::fromArray($report->kpis);
            } catch (InvalidArgumentException|TypeError|ErrorException $e) {
                report(ReportKpisUnavailableException::unreadableSnapshot($report, $e));
                $frozen[$report->unit_id] = null;
            }
        }

        return $frozen;
    }
}
