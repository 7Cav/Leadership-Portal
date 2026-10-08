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
use InvalidArgumentException;
use TypeError;
use UnexpectedValueException;

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
 * Two queries regardless of the number of children. Callers pass only units the ScopeResolver
 * permits (AD-8), as for `KpiService`.
 */
final class RollupKpis
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
    ) {}

    /**
     * @throws InvalidArgumentException when `$unit` has no children (a leaf reports from
     *                                  attendance, via `KpiService`) or `$weekStart` is not a
     *                                  Saturday 00:00 UTC.
     * @throws UnexpectedValueException when a child's sent snapshot cannot be read as `UnitKpis`.
     */
    public function forUnit(Unit $unit, CarbonInterface $weekStart): RollupResult
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $children = $this->units->children($unit);

        if ($children->isEmpty()) {
            throw new InvalidArgumentException("Unit {$unit->id} has no children; a leaf report computes from attendance, not a roll-up.");
        }

        $reports = $this->reports->forUnits($children->modelKeys(), $weekStart);

        return new RollupResult($children
            ->map(fn (Unit $child) => $this->childReport($child, $reports->get($child->id), $weekStart))
            ->values()
            ->all());
    }

    private function childReport(Unit $child, ?WeeklyReport $report, CarbonImmutable $weekStart): ChildReport
    {
        return match ($report?->status) {
            null => new ChildReport($child, ChildReportStatus::NotStarted, null),
            WeeklyReportStatus::Draft => new ChildReport($child, ChildReportStatus::Draft, null),
            WeeklyReportStatus::Sent => new ChildReport($child, ChildReportStatus::Sent, $this->snapshot($report, $weekStart)),
        };
    }

    /**
     * A sent snapshot that does not read back as `UnitKpis` is a corrupt record, not a zero: it
     * fails loudly with the unit-week named rather than quietly shrinking the parent's numbers.
     */
    private function snapshot(WeeklyReport $report, CarbonImmutable $weekStart): UnitKpis
    {
        try {
            return UnitKpis::fromArray($report->kpis);
        } catch (InvalidArgumentException|TypeError|ErrorException $e) {
            throw new UnexpectedValueException(
                "The sent report of unit {$report->unit_id} for {$weekStart->toDateString()} has an unreadable KPI snapshot: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
