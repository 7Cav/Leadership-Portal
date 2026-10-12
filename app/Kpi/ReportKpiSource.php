<?php

namespace App\Kpi;

use App\Models\Unit;
use Carbon\CarbonImmutable;

/**
 * Where a weekly report's six KPIs come from, by echelon — the seam 5.6's send asks, so the
 * freeze does not care how a unit's numbers are made ({@see EchelonReportKpiSource}):
 *
 * - a **section** (leaf) reports its live KPIs from {@see KpiService} (AD-21);
 * - a **non-leaf** unit reports the aggregate of its direct children's **sent** snapshots
 *   ({@see RollupKpis}, 5.7), never a live re-aggregation. A child still draft or not started is
 *   left out of the sum and named in `ReportKpis::$missingChildren`, so the report can be sent
 *   without it and say who was missing.
 */
interface ReportKpiSource
{
    /**
     * @param  CarbonImmutable  $weekStart  a Saturday 00:00 UTC (AD-11)
     *
     * @throws ReportKpisUnavailableException when the unit's KPIs cannot be reported.
     */
    public function kpisFor(Unit $unit, CarbonImmutable $weekStart): ReportKpis;
}
