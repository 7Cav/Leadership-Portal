<?php

namespace App\Kpi;

/**
 * A non-leaf unit's report for one week (5.7, AD-13): `kpis` is the sum of its direct children's
 * sent snapshots only, and `children` lists every direct child — sent or not — in order. A child
 * that has not sent is excluded from `kpis`, never estimated, and named by `missingChildren()` so
 * the report and the send step (5.5/5.6) can say who is holding up the chain.
 */
final readonly class RollupResult
{
    /**
     * @param  list<ChildReport>  $children
     */
    public function __construct(
        public UnitKpis $kpis,
        public array $children,
    ) {}

    /**
     * The children still draft or not started, in order.
     *
     * @return list<ChildReport>
     */
    public function missingChildren(): array
    {
        return array_values(array_filter($this->children, fn (ChildReport $child) => $child->isMissing()));
    }

    /** Every direct child has sent — the aggregate covers the whole unit. */
    public function isComplete(): bool
    {
        return $this->missingChildren() === [];
    }
}
