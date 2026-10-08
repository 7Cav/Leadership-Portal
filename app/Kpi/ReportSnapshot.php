<?php

namespace App\Kpi;

use InvalidArgumentException;

/**
 * What a weekly report freezes into `weekly_reports.kpis` on send (AD-12, 5.6): the six KPIs in
 * `UnitKpis::toArray()`'s storage shape, plus their week-over-week `delta` — null when the prior
 * week has no sent report (a first report, a skipped week, or a prior week still in draft).
 * Readers of a sent report rebuild it through `fromArray()`, never by recomputing it.
 *
 * Built by {@see WeeklyReportKpis}; `UnitKpis::fromArray()` reads the KPI keys of this shape too,
 * so a roll-up (5.7) can sum children's snapshots without caring about the delta.
 */
final readonly class ReportSnapshot
{
    private const KEYS = ['strength', 'rc_30d', 'sp_30d', 'at_risk', 'on_loa', 'makeups', 'delta'];

    private const DELTA_KEYS = ['strength', 'rc_30d_bp', 'sp_30d_bp', 'at_risk', 'on_loa', 'makeups'];

    public function __construct(
        public UnitKpis $kpis,
        public ?KpiDelta $delta,
    ) {}

    /**
     * @return array{strength: int, rc_30d: array{numerator: int, denominator: int}, sp_30d: array{numerator: int, denominator: int}, at_risk: int, on_loa: int, makeups: int, delta: array{strength: int, rc_30d_bp: ?int, sp_30d_bp: ?int, at_risk: int, on_loa: int, makeups: int}|null}
     */
    public function toArray(): array
    {
        return [...$this->kpis->toArray(), 'delta' => $this->delta?->toArray()];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     *
     * @throws InvalidArgumentException when the stored array is not this shape — a key missing
     *                                  (a `delta`-less array must not pass as a first report) or a
     *                                  value no computation could produce.
     */
    public static function fromArray(array $snapshot): self
    {
        self::requireKeys($snapshot, self::KEYS, 'report snapshot');

        if ($snapshot['delta'] !== null) {
            self::requireKeys((array) $snapshot['delta'], self::DELTA_KEYS, 'report snapshot delta');
        }

        return new self(
            UnitKpis::fromArray($snapshot),
            $snapshot['delta'] === null ? null : KpiDelta::fromArray($snapshot['delta']),
        );
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
     */
    private static function requireKeys(array $array, array $keys, string $what): void
    {
        $missing = array_diff($keys, array_keys($array));

        if ($missing !== []) {
            throw new InvalidArgumentException("Not a {$what}: missing ".implode(', ', $missing).'.');
        }
    }
}
