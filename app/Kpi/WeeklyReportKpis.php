<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\WeeklyReportStatus;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonInterface;
use ErrorException;
use InvalidArgumentException;
use TypeError;

/**
 * A unit-week's report snapshot (5.6): its six KPIs from the echelon's {@see ReportKpiSource},
 * and the {@see Headcount} beside them as it stood that week, and the delta against the same unit's prior-week **sent** snapshot (5.4). A prior week that is
 * missing or still a draft gives no delta — the report reads as a first report. Nor does a roll-up when either week is
 * short of a child's report (#625): the two weeks sum different units, so the change would only be the missing ones.
 *
 * The send Command freezes this; the report screen (5.3/5.4) renders it live for a draft. A
 * *sent* report is read back from its stored snapshot (`ReportSnapshot::fromArray()`), never
 * through here (AD-12).
 */
final class WeeklyReportKpis
{
    public function __construct(
        private readonly ReportKpiSource $source,
        private readonly WeeklyReportRepository $reports,
        private readonly Headcount $headcounts,
    ) {}

    /**
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     * @throws ReportKpisUnavailableException when the unit's KPIs cannot be reported (yet), or the
     *                                        prior week's sent snapshot cannot be read.
     */
    public function snapshot(Unit $unit, CarbonInterface $weekStart): ReportSnapshot
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $sourced = $this->source->kpisFor($unit, $weekStart);
        $kpis = $sourced->kpis;
        $headcount = $this->headcounts->forUnit($unit, $weekStart);
        $prior = $this->reports->find($unit->id, $weekStart->subWeek());

        // A roll-up still waiting on a child is not comparable with last week (#625): its sums drop by whatever has
        // not been sent yet, and a delta frozen now would keep showing that drop.
        if ($prior?->status !== WeeklyReportStatus::Sent || $sourced->missingChildren !== []) {
            return new ReportSnapshot($kpis, null, $sourced->missingChildren, $headcount);
        }

        try {
            $priorSnapshot = ReportSnapshot::fromArray($prior->kpis);
        } catch (InvalidArgumentException|TypeError|ErrorException $unreadable) {
            // A missing key, an impossible value, or a value of the wrong type in the stored JSON.
            throw ReportKpisUnavailableException::unreadablePrior($unit, $weekStart->subWeek(), $unreadable);
        }

        // Nor is one with last week's, when last week was sent without some of its children.
        if ($priorSnapshot->missingChildren !== []) {
            return new ReportSnapshot($kpis, null, $sourced->missingChildren, $headcount);
        }

        return new ReportSnapshot($kpis, KpiDelta::between($kpis, $priorSnapshot->kpis), $sourced->missingChildren, $headcount);
    }
}
