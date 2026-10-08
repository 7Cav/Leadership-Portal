<?php

namespace App\Kpi;

use App\Models\Unit;
use InvalidArgumentException;

/**
 * One direct child in a roll-up (5.7): the unit, where its report for the week stands, and — only
 * once sent — its frozen KPI snapshot. A draft child's numbers are never exposed here, so nothing
 * downstream can substitute them for a sent snapshot (AD-13).
 */
final readonly class ChildReport
{
    public function __construct(
        public Unit $unit,
        public ChildReportStatus $status,
        /** The sent snapshot; null for a draft or unstarted child. */
        public ?UnitKpis $kpis,
    ) {
        if (($status === ChildReportStatus::Sent) !== ($kpis !== null)) {
            throw new InvalidArgumentException('A child report carries KPIs exactly when it is sent.');
        }
    }

    /** Still draft or not started: holding up the chain. */
    public function isMissing(): bool
    {
        return $this->status !== ChildReportStatus::Sent;
    }
}
