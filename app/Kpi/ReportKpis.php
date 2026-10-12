<?php

namespace App\Kpi;

/**
 * What a {@see ReportKpiSource} hands a weekly report: the unit's six KPIs, and — for a non-leaf
 * roll-up — the ids of the direct children left out of them because they had not sent yet
 * (5.7, AD-13). A section has no children, so its list is always empty.
 */
final readonly class ReportKpis
{
    /**
     * @param  list<int>  $missingChildren
     */
    public function __construct(
        public UnitKpis $kpis,
        public array $missingChildren = [],
    ) {}
}
