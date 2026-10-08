<?php

namespace App\Kpi;

use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Bind;

/**
 * Where a weekly report's six KPIs come from, by echelon — the seam 5.6's send asks, so the
 * freeze does not care how a unit's numbers are made:
 *
 * - a **section** (leaf) reports its live KPIs from {@see KpiService} (AD-21);
 * - a **non-leaf** unit reports the aggregate of its direct children's **sent** snapshots (5.7),
 *   never a live re-aggregation, and cannot report while a child is still a draft.
 *
 * TODO(5.7): only the leaf half exists ({@see SectionReportKpiSource}, which refuses a non-leaf).
 * 5.7 supplies the non-leaf half — sum the children's sent snapshots, throw
 * `ReportKpisUnavailableException` while any child holds up the chain — and rebinds this
 * interface to an implementation that routes by unit type.
 */
#[Bind(SectionReportKpiSource::class)]
interface ReportKpiSource
{
    /**
     * @param  CarbonImmutable  $weekStart  a Saturday 00:00 UTC (AD-11)
     *
     * @throws ReportKpisUnavailableException when the unit's KPIs cannot be reported (yet).
     */
    public function kpisFor(Unit $unit, CarbonImmutable $weekStart): UnitKpis;
}
