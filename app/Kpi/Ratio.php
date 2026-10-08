<?php

namespace App\Kpi;

use InvalidArgumentException;

/**
 * A KPI percentage kept as its parts (AD-21): a roll-up (5.7) sums its children's numerators and
 * denominators before dividing, never averages their percentages. A zero denominator means
 * nothing was eligible — `value()` is then null (rendered "—"), never a misleading 0%.
 */
final readonly class Ratio
{
    public function __construct(
        public int $numerator,
        public int $denominator,
    ) {
        if ($numerator < 0 || $denominator < 0 || $numerator > $denominator) {
            throw new InvalidArgumentException("Not a valid ratio: {$numerator}/{$denominator}.");
        }
    }

    /** Parts summed, never percentages averaged — how a unit composes its members' ratios. */
    public function plus(Ratio $other): self
    {
        return new self($this->numerator + $other->numerator, $this->denominator + $other->denominator);
    }

    /** The fraction in [0, 1], or null when nothing was eligible. */
    public function value(): ?float
    {
        return $this->denominator === 0 ? null : $this->numerator / $this->denominator;
    }
}
