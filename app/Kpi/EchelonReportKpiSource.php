<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;

/**
 * {@see ReportKpiSource} by echelon: a section reports its live KPIs from the shared service; any
 * other unit reports the roll-up of its direct children's sent snapshots, with the children that
 * have not sent listed rather than estimated (AD-13). A non-leaf unit with no children has
 * nothing to roll up and is refused.
 */
final class EchelonReportKpiSource implements ReportKpiSource
{
    public function __construct(
        private readonly KpiService $kpis,
        private readonly RollupKpis $rollups,
        private readonly UnitRepository $units,
    ) {}

    public function kpisFor(Unit $unit, CarbonImmutable $weekStart): ReportKpis
    {
        if ($unit->type === UnitType::Section) {
            return new ReportKpis($this->kpis->forUnit($unit, $weekStart));
        }

        if (! $this->units->hasChildren($unit)) {
            throw ReportKpisUnavailableException::noChildren($unit);
        }

        $rollup = $this->rollups->forUnit($unit, $weekStart);

        return new ReportKpis(
            $rollup->kpis,
            array_map(fn (ChildReport $child) => $child->unit->id, $rollup->missingChildren()),
        );
    }
}
