<?php

namespace App\Kpi;

/**
 * The week-over-week change in a unit's six KPIs (5.4): this week's `UnitKpis` minus the same
 * unit's prior-week **sent** snapshot — never a live recompute of the prior week, so the delta a
 * report shows is against what was actually reported. Computed here, once, so the report screen
 * only renders it (AD-21).
 *
 * Counts are signed differences. A rate's delta is the change in its fraction (`0.25` = up 25
 * points), and null when either week had nothing eligible — a rate that was "—" has no change
 * to report, never a misleading ±0. A unit with no prior sent report has no `KpiDelta` at all
 * (the "first report" case), which is the caller's null, not a zeroed delta.
 */
final readonly class KpiDelta
{
    public function __construct(
        public int $strength,
        public ?float $rc30d,
        public ?float $sp30d,
        public int $atRisk,
        public int $onLoa,
        public int $makeups,
    ) {}

    public static function between(UnitKpis $current, UnitKpis $prior): self
    {
        return new self(
            strength: $current->strength - $prior->strength,
            rc30d: self::rateChange($current->rc30d, $prior->rc30d),
            sp30d: self::rateChange($current->sp30d, $prior->sp30d),
            atRisk: $current->atRisk - $prior->atRisk,
            onLoa: $current->onLoa - $prior->onLoa,
            makeups: $current->makeups - $prior->makeups,
        );
    }

    /**
     * @return array{strength: int, rc_30d: ?float, sp_30d: ?float, at_risk: int, on_loa: int, makeups: int}
     */
    public function toArray(): array
    {
        return [
            'strength' => $this->strength,
            'rc_30d' => $this->rc30d,
            'sp_30d' => $this->sp30d,
            'at_risk' => $this->atRisk,
            'on_loa' => $this->onLoa,
            'makeups' => $this->makeups,
        ];
    }

    /**
     * @param  array{strength: int, rc_30d: int|float|null, sp_30d: int|float|null, at_risk: int, on_loa: int, makeups: int}  $delta
     */
    public static function fromArray(array $delta): self
    {
        return new self(
            strength: $delta['strength'],
            rc30d: $delta['rc_30d'] === null ? null : (float) $delta['rc_30d'],
            sp30d: $delta['sp_30d'] === null ? null : (float) $delta['sp_30d'],
            atRisk: $delta['at_risk'],
            onLoa: $delta['on_loa'],
            makeups: $delta['makeups'],
        );
    }

    private static function rateChange(Ratio $current, Ratio $prior): ?float
    {
        $now = $current->value();
        $then = $prior->value();

        return $now === null || $then === null ? null : $now - $then;
    }
}
