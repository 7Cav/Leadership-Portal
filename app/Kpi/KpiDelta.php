<?php

namespace App\Kpi;

use InvalidArgumentException;

/**
 * The week-over-week change in a unit's six KPIs (5.4): this week's `UnitKpis` minus the same
 * unit's prior-week **sent** snapshot — never a live recompute of the prior week, so the delta a
 * report shows is against what was actually reported. Computed here, once, so the report screen
 * only renders it (AD-21).
 *
 * Counts are signed differences. A rate's delta is the change in its fraction in **basis points**
 * (1 bp = 0.01 percentage points; `2500` = up 25 points), rounded to the nearest point — an
 * integer, so the frozen snapshot carries no float a JSON column could re-round and so its
 * `content_digest` stays verifiable. Null when either week had nothing eligible: a rate that was
 * "—" has no change to report, never a misleading ±0. A unit-week whose prior week has no sent
 * report has no `KpiDelta` at all — the caller's null, not a zeroed delta.
 */
final readonly class KpiDelta
{
    private const MAX_BASIS_POINTS = 10_000;

    /**
     * @throws InvalidArgumentException when a rate delta lies outside ±10000 bp — no change between
     *                                  two fractions in [0, 1] can, so a corrupt snapshot fails loudly.
     */
    public function __construct(
        public int $strength,
        public ?int $rc30dBp,
        public ?int $sp30dBp,
        public int $atRisk,
        public int $onLoa,
        public int $makeups,
    ) {
        foreach (['rc30dBp' => $rc30dBp, 'sp30dBp' => $sp30dBp] as $name => $bp) {
            if ($bp !== null && abs($bp) > self::MAX_BASIS_POINTS) {
                throw new InvalidArgumentException("{$name} must be within ±".self::MAX_BASIS_POINTS." basis points; got {$bp}.");
            }
        }
    }

    public static function between(UnitKpis $current, UnitKpis $prior): self
    {
        return new self(
            strength: $current->strength - $prior->strength,
            rc30dBp: self::rateChange($current->rc30d, $prior->rc30d),
            sp30dBp: self::rateChange($current->sp30d, $prior->sp30d),
            atRisk: $current->atRisk - $prior->atRisk,
            onLoa: $current->onLoa - $prior->onLoa,
            makeups: $current->makeups - $prior->makeups,
        );
    }

    /**
     * @return array{strength: int, rc_30d_bp: ?int, sp_30d_bp: ?int, at_risk: int, on_loa: int, makeups: int}
     */
    public function toArray(): array
    {
        return [
            'strength' => $this->strength,
            'rc_30d_bp' => $this->rc30dBp,
            'sp_30d_bp' => $this->sp30dBp,
            'at_risk' => $this->atRisk,
            'on_loa' => $this->onLoa,
            'makeups' => $this->makeups,
        ];
    }

    /**
     * @param  array{strength: int, rc_30d_bp: ?int, sp_30d_bp: ?int, at_risk: int, on_loa: int, makeups: int}  $delta
     */
    public static function fromArray(array $delta): self
    {
        return new self(
            strength: $delta['strength'],
            rc30dBp: $delta['rc_30d_bp'],
            sp30dBp: $delta['sp_30d_bp'],
            atRisk: $delta['at_risk'],
            onLoa: $delta['on_loa'],
            makeups: $delta['makeups'],
        );
    }

    private static function rateChange(Ratio $current, Ratio $prior): ?int
    {
        $now = $current->value();
        $then = $prior->value();

        return $now === null || $then === null ? null : (int) round(($now - $then) * self::MAX_BASIS_POINTS);
    }
}
