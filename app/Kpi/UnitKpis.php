<?php

namespace App\Kpi;

/**
 * The six canonical KPIs for one unit's subtree and one Cav week (AD-21) — exactly these, and
 * nothing more. Every field is additive over a unit's children (each trooper sits in exactly one
 * section), which is what lets a roll-up (5.7) compose from its children's snapshots. Built only
 * by `KpiService`; `toArray()`/`fromArray()` are the frozen-snapshot storage shape (5.2).
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
    ) {}

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
