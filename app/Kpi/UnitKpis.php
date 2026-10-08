<?php

namespace App\Kpi;

use InvalidArgumentException;

/**
 * The six canonical KPIs for one unit's subtree and one Cav week (AD-21) — exactly these, and
 * nothing more. Every field is additive over a unit's children (each trooper sits in exactly one
 * section), which is what lets a roll-up (5.7) compose from its children's snapshots — that
 * composition belongs here in `App\Kpi` too, never in its caller (AD-21). Computed by
 * `KpiService`; `toArray()`/`fromArray()` are the frozen-snapshot storage shape (5.2), and the
 * constructor refuses counts no computation could produce, so a corrupt snapshot fails loudly.
 */
final readonly class UnitKpis
{
    public function __construct(
        public int $strength,
        public Ratio $rc30d,
        public Ratio $sp30d,
        public int $atRisk,
        public int $onLoa,
        public int $makeups,
    ) {
        foreach (['atRisk' => $atRisk, 'onLoa' => $onLoa, 'makeups' => $makeups] as $name => $count) {
            if ($count < 0 || $count > $strength) {
                throw new InvalidArgumentException("{$name} must be between 0 and strength ({$strength}); got {$count}.");
            }
        }
    }

    /** A unit with no troopers — the starting point a roll-up adds its children's KPIs onto. */
    public static function none(): self
    {
        return new self(0, new Ratio(0, 0), new Ratio(0, 0), 0, 0, 0);
    }

    /**
     * This unit's KPIs plus a disjoint unit's — how a roll-up composes its children (5.7): counts
     * summed, ratios composed from their parts ({@see Ratio::plus()}), never averaged.
     */
    public function plus(UnitKpis $other): self
    {
        return new self(
            strength: $this->strength + $other->strength,
            rc30d: $this->rc30d->plus($other->rc30d),
            sp30d: $this->sp30d->plus($other->sp30d),
            atRisk: $this->atRisk + $other->atRisk,
            onLoa: $this->onLoa + $other->onLoa,
            makeups: $this->makeups + $other->makeups,
        );
    }

    /**
     * @return array{strength: int, rc_30d: array{numerator: int, denominator: int}, sp_30d: array{numerator: int, denominator: int}, at_risk: int, on_loa: int, makeups: int}
     */
    public function toArray(): array
    {
        return [
            'strength' => $this->strength,
            'rc_30d' => ['numerator' => $this->rc30d->numerator, 'denominator' => $this->rc30d->denominator],
            'sp_30d' => ['numerator' => $this->sp30d->numerator, 'denominator' => $this->sp30d->denominator],
            'at_risk' => $this->atRisk,
            'on_loa' => $this->onLoa,
            'makeups' => $this->makeups,
        ];
    }

    /**
     * @param  array{strength: int, rc_30d: array{numerator: int, denominator: int}, sp_30d: array{numerator: int, denominator: int}, at_risk: int, on_loa: int, makeups: int}  $kpis
     */
    public static function fromArray(array $kpis): self
    {
        return new self(
            strength: $kpis['strength'],
            rc30d: new Ratio($kpis['rc_30d']['numerator'], $kpis['rc_30d']['denominator']),
            sp30d: new Ratio($kpis['sp_30d']['numerator'], $kpis['sp_30d']['denominator']),
            atRisk: $kpis['at_risk'],
            onLoa: $kpis['on_loa'],
            makeups: $kpis['makeups'],
        );
    }
}
