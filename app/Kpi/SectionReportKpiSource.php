<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\UnitType;
use Carbon\CarbonImmutable;

/**
 * The leaf half of {@see ReportKpiSource}: a section reports its live KPIs from the shared
 * service. Any other echelon is refused rather than computed live — a non-leaf report is the
 * roll-up of its children's sent snapshots (5.7, not built yet), and a live re-aggregation in
 * its place is exactly the drift that roll-up exists to prevent.
 */
final class SectionReportKpiSource implements ReportKpiSource
{
    public function __construct(private readonly KpiService $kpis) {}

    public function kpisFor(Unit $unit, CarbonImmutable $weekStart): UnitKpis
    {
        if ($unit->type !== UnitType::Section) {
            throw ReportKpisUnavailableException::rollupPending($unit);
        }

        return $this->kpis->forUnit($unit, $weekStart);
    }
}
