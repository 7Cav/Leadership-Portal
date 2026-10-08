<?php

namespace App\Kpi;

use InvalidArgumentException;

/**
 * A non-leaf unit's report for one week (5.7, AD-13): `children` lists every direct child — sent
 * or not — in order, and `kpis` is derived here from the sent ones only, so the total can never
 * disagree with its rows. A child that has not sent is excluded from `kpis`, never estimated, and
 * named by `missingChildren()` so the report and the send step (5.5/5.6) can say who is holding
 * up the chain.
 */
final readonly class RollupResult
{
    /** The sum of the sent children's snapshots (`UnitKpis::plus()`). */
    public UnitKpis $kpis;

    /**
     * @param  non-empty-list<ChildReport>  $children
     *
     * @throws InvalidArgumentException when `$children` is empty — a leaf has no roll-up.
     */
    public function __construct(
        public array $children,
    ) {
        if ($children === []) {
            throw new InvalidArgumentException('A roll-up has at least one child.');
        }

        $this->kpis = array_reduce(
            $children,
            fn (UnitKpis $sum, ChildReport $child) => $child->kpis === null ? $sum : $sum->plus($child->kpis),
            UnitKpis::none(),
        );
    }

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
