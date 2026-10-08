<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\WeeklyReport;

/**
 * One unit in the report chain for one Cav week (5.8): where its own report stands, the six KPIs
 * a reviewer sees for it, and its children. Built only by {@see ReportChain}.
 *
 * `kpis` is the frozen snapshot once the unit has sent (AD-12). Until then it is live and marked
 * so: a leaf's KPIs from `KpiService`, a non-leaf's roll-up of its children's **sent** snapshots
 * (5.7, AD-13) — the numbers its report would freeze, never a draft child's.
 */
final readonly class ChainNode
{
    /**
     * @param  list<ChainNode>  $children  in creation order
     */
    public function __construct(
        public Unit $unit,
        public ChildReportStatus $status,
        public UnitKpis $kpis,
        /** False once sent: `kpis` and `delta` are then the frozen snapshot. */
        public bool $live,
        /** Against the prior week's sent snapshot; null when that week has none (5.4). */
        public ?KpiDelta $delta,
        /** The unit's report row for the week, draft or sent; null when not started. */
        public ?WeeklyReport $report,
        /** The roll-up of its direct children; null for a leaf. */
        public ?RollupResult $rollup,
        public array $children,
    ) {}

    /** Not sent, and waiting on a direct child that has not sent either (AD-13). */
    public function isHolding(): bool
    {
        return $this->status !== ChildReportStatus::Sent && $this->rollup !== null && ! $this->rollup->isComplete();
    }

    /** This unit or anything under it has not sent: the branch still needs action. */
    public function hasOpenReports(): bool
    {
        if ($this->status !== ChildReportStatus::Sent) {
            return true;
        }

        foreach ($this->children as $child) {
            if ($child->hasOpenReports()) {
                return true;
            }
        }

        return false;
    }
}
