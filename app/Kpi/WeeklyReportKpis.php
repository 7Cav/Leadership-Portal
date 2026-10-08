<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\WeeklyReportStatus;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A unit-week's report snapshot (5.6): its six KPIs from the echelon's {@see ReportKpiSource},
 * and the delta against the same unit's prior-week **sent** snapshot (5.4). A prior week that is
 * missing or still a draft gives no delta — the report reads as a first report.
 *
 * The send Command freezes this; the report screen (5.4/5.8) is to render it live for a draft. A
 * *sent* report is read back from its stored snapshot (`ReportSnapshot::fromArray()`), never
 * through here (AD-12).
 */
final class WeeklyReportKpis
{
    public function __construct(
        private readonly ReportKpiSource $source,
        private readonly WeeklyReportRepository $reports,
    ) {}

    /**
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     * @throws ReportKpisUnavailableException when the unit's KPIs cannot be reported (yet), or the
     *                                        prior week's sent snapshot cannot be read.
     */
    public function snapshot(Unit $unit, CarbonInterface $weekStart): ReportSnapshot
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $kpis = $this->source->kpisFor($unit, $weekStart);
        $prior = $this->reports->find($unit->id, $weekStart->subWeek());

        if ($prior?->status !== WeeklyReportStatus::Sent) {
            return new ReportSnapshot($kpis, null);
        }

        try {
            $priorKpis = ReportSnapshot::fromArray($prior->kpis)->kpis;
        } catch (InvalidArgumentException $unreadable) {
            throw ReportKpisUnavailableException::unreadablePrior($unit, $weekStart->subWeek(), $unreadable);
        }

        return new ReportSnapshot($kpis, KpiDelta::between($kpis, $priorKpis));
    }
}
