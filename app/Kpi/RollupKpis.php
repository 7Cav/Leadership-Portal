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
 * A non-leaf unit's KPIs for one Cav week, composed from its **direct** children's **sent**
 * snapshots (AD-13) — never re-aggregated from live attendance, and never from a grandchild's
 * report, so a company always agrees with what its platoons actually sent. The sum follows the
 * canonical definitions (AD-21) via `UnitKpis::plus()`: counts added, RC/SP composed from summed
 * numerators and denominators.
 *
 * A child still draft, or with no report for the week, is left out of the sum and listed as
 * holding up the chain (`RollupResult::missingChildren()`); its live numbers are never
 * substituted. The 5.6 send command freezes `RollupResult::$kpis` for a non-leaf unit and the 5.8
 * navigator shows `RollupResult::$children` — both read it from here.
 *
 * A line child with no report to roll up is not one of the children (#442, #478, {@see children()}): an empty
 * section, or a unit with no section under it that has troopers (its HQ staff alone report nothing). It is neither
 * summed nor waited on, until someone is placed in a section under it.
 *
 * Four queries regardless of the number of children. Callers pass only units the ScopeResolver
 * permits (AD-8), as for `KpiService`.
 */
final class RollupKpis
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly Headcount $headcount,
    ) {}

    /**
     * The children `$unit`'s report rolls up and waits on: its direct line children (a group under the regiment has
     * no report to wait on, 2026-10-24) that take part in the report chain ({@see Headcount::reportingLineUnits()},
     * #442, #478), in creation order. A section takes part when it has troopers, a unit above one when a unit under it
     * does, so a platoon whose only troopers are its HQ is left out like an empty one. Empty for a leaf, and for a unit
     * none of whose children take part. With `$weekStart`, a section that had troopers at any point of that week takes
     * part too, and so does any unit that sent that week's report, so one emptied after reporting still rolls up for it.
     *
     * @return Collection<int, Unit>
     */
    public function children(Unit $unit, ?CarbonInterface $weekStart = null): Collection
    {
        // The whole line subtree, not just the children: whether a child takes part can rest on a section far below it
        // that sent. One query either way, as is the week's reports.
        $subtree = $this->units->lineSubtree($unit);
        $sent = $weekStart === null ? [] : self::sentIds($this->reports->forUnits($subtree->modelKeys(), WeekOf::requireStart($weekStart)));

        return $this->headcount->reportingLineUnits(self::directChildren($unit, $subtree), $weekStart, $sent);
    }

    /**
     * @param  Collection<int, Unit>  $subtree  `$unit`'s line subtree, in id order
     * @return Collection<int, Unit> its direct children among them, in id (creation) order
     */
    private static function directChildren(Unit $unit, Collection $subtree): Collection
    {
        return $subtree->filter(fn (Unit $child) => $child->parent_id === $unit->id)->values();
    }

    /**
     * @param  Collection<int, WeeklyReport>  $reports  keyed by `unit_id`
     * @return list<int> the units among them whose report is sent
     */
    private static function sentIds(Collection $reports): array
    {
        return $reports->filter(fn (WeeklyReport $report) => $report->status === WeeklyReportStatus::Sent)->keys()->all();
    }

    /**
     * @throws InvalidArgumentException when `$unit` has no {@see children()} (a leaf reports from
     *                                  attendance, via `KpiService`) or `$weekStart` is not a
     *                                  Saturday 00:00 UTC.
     * @throws ReportKpisUnavailableException when a child's sent snapshot cannot be read as `UnitKpis`.
     */
    public function forUnit(Unit $unit, CarbonInterface $weekStart): RollupResult
    {
        $weekStart = WeekOf::requireStart($weekStart);
        // One read of the week's reports, over the whole line subtree, serves both which children take part (a unit that
        // sent always does, and so does one above it) and their numbers.
        $subtree = $this->units->lineSubtree($unit);
        $reports = $this->reports->forUnits($subtree->modelKeys(), $weekStart);
        $children = $this->headcount->reportingLineUnits(self::directChildren($unit, $subtree), $weekStart, self::sentIds($reports));

        if ($children->isEmpty()) {
            throw new InvalidArgumentException("Unit {$unit->id} has no children; a leaf report computes from attendance, not a roll-up.");
        }

        return self::fromReports($children, $reports, $weekStart);
    }

    /**
     * The same roll-up from children and reports the caller already loaded — how 5.8's navigator
     * rolls up every non-leaf node of a battalion from one `forUnits()` read instead of two
     * queries per node. `$reports` is keyed by `unit_id` and may hold other units' reports too;
     * a child without one is not started.
     *
     * @param  Collection<int, Unit>  $children  the unit's direct children, in order
     * @param  Collection<int, WeeklyReport>  $reports  the week's reports, keyed by `unit_id`
     *
     * @throws InvalidArgumentException when `$children` is empty, `$weekStart` is not a
     *                                  Saturday 00:00 UTC, or a report is not the child's own for
     *                                  that week. That `$children` are one unit's direct children
     *                                  is the caller's to ensure.
     * @throws ReportKpisUnavailableException when a child's sent snapshot cannot be read as `UnitKpis`.
     */
    public static function fromReports(Collection $children, Collection $reports, CarbonInterface $weekStart): RollupResult
    {
        $weekStart = WeekOf::requireStart($weekStart);

        return new RollupResult($children
            ->map(fn (Unit $child) => self::childReport($child, $reports->get($child->id), $weekStart))
            ->values()
            ->all());
    }

    private static function childReport(Unit $child, ?WeeklyReport $report, CarbonImmutable $weekStart): ChildReport
    {
        // Handed in by the caller since 5.8: a report keyed to the wrong unit or week would roll
        // up silently as this child's, so it is refused instead.
        if ($report !== null && ($report->unit_id !== $child->id || ! $report->week_start->equalTo($weekStart))) {
            throw new InvalidArgumentException("The report given for unit {$child->id} belongs to unit {$report->unit_id}, week {$report->week_start->toDateString()}; expected week {$weekStart->toDateString()}.");
        }

        return match ($report?->status) {
            null => new ChildReport($child, ChildReportStatus::NotStarted, null),
            WeeklyReportStatus::Draft => new ChildReport($child, ChildReportStatus::Draft, null),
            WeeklyReportStatus::Sent => new ChildReport($child, ChildReportStatus::Sent, self::snapshot($report)),
        };
    }

    /**
     * A sent snapshot that does not read back as `UnitKpis` is a corrupt record, not a zero: it
     * fails loudly with the unit-week named rather than quietly shrinking the parent's numbers, as
     * the unit's KPIs being unavailable, so a send is refused and a page can say so.
     */
    private static function snapshot(WeeklyReport $report): UnitKpis
    {
        try {
            return UnitKpis::fromArray($report->kpis);
        } catch (InvalidArgumentException|TypeError|ErrorException $e) {
            throw ReportKpisUnavailableException::unreadableSnapshot($report, $e);
        }
    }
}
