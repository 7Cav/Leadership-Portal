<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use InvalidArgumentException;

/**
 * One unit in the report chain for one Cav week (5.8): where its own report stands, the six KPIs
 * a reviewer sees for it, and its children. Built only by {@see ReportChain}.
 *
 * `kpis` is the frozen snapshot once the unit has sent (AD-12). Until then it is live and marked
 * so: a leaf's KPIs from `KpiService`, a non-leaf's roll-up of its children's **sent** snapshots
 * (5.7, AD-13) — the numbers its report would freeze, never a draft child's. `status`, `live` and
 * the open-branch flag are derived here from the report and the children, so they cannot disagree.
 *
 * `kpis` is null when they cannot be read: the unit's own sent snapshot is corrupt, or, unsent,
 * a direct child's is, which also leaves a non-leaf without a `rollup`. The page shows that unit
 * as unavailable rather than failing for everyone above it.
 */
final readonly class ChainNode
{
    public ChildReportStatus $status;

    /** True until sent; once sent, `kpis` and `delta` are the frozen snapshot. */
    public bool $live;

    /** This unit or anything under it has not sent: the branch still needs action. */
    public bool $open;

    /**
     * @param  WeeklyReport|null  $report  the unit's report row for the week, null when not
     *                                     started — read for its metadata (notes, send time and
     *                                     sender) only: a draft's stored `kpis` are never shown,
     *                                     `kpis` here is what a reviewer sees.
     * @param  list<ChainNode>  $children  in creation order
     *
     * @throws InvalidArgumentException when a roll-up is given and it and the children disagree.
     */
    public function __construct(
        public Unit $unit,
        public ?WeeklyReport $report,
        /** Null when they cannot be read (see above). */
        public ?UnitKpis $kpis,
        /** Against the prior week's sent snapshot; null when that week has none (5.4) or it cannot be read. */
        public ?KpiDelta $delta,
        /** The roll-up of its direct children; null for a leaf, or when a child's sent snapshot cannot be read. */
        public ?RollupResult $rollup,
        public array $children,
    ) {
        $rolledUp = $rollup === null ? [] : array_map(fn (ChildReport $child) => $child->unit->id, $rollup->children);

        if ($rollup !== null && $rolledUp !== array_map(fn (ChainNode $child) => $child->unit->id, $children)) {
            throw new InvalidArgumentException("The roll-up of unit {$unit->id} does not match its children.");
        }

        $this->status = match ($report?->status) {
            null => ChildReportStatus::NotStarted,
            WeeklyReportStatus::Draft => ChildReportStatus::Draft,
            WeeklyReportStatus::Sent => ChildReportStatus::Sent,
        };
        $this->live = $this->status !== ChildReportStatus::Sent;
        $this->open = $this->live || array_filter($children, fn (ChainNode $child) => $child->open) !== [];
    }

    /** Not sent, and waiting on a direct child that has not sent either (AD-13). */
    public function isHolding(): bool
    {
        return $this->live && $this->rollup !== null && ! $this->rollup->isComplete();
    }
}
