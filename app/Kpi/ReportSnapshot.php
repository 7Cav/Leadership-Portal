<?php

namespace App\Kpi;

use InvalidArgumentException;

/**
 * What a weekly report freezes into `weekly_reports.kpis` on send (AD-12, 5.6): the six KPIs in
 * `UnitKpis::toArray()`'s storage shape, plus their week-over-week `delta` — null when the prior
 * week has no sent report (a first report, a skipped week, or a prior week still in draft) — and,
 * for a roll-up, the children it was sent without (`missing_children`).
 * Readers of a sent report rebuild it through `fromArray()`, never by recomputing it.
 *
 * `headcount` (#424) is the unit's whole serving headcount at send time, HQ staff and groups included ({@see Headcount}). It
 * is beside the six KPIs, never one of them: it is not summed by a roll-up and no rate reads it. A snapshot frozen
 * before it existed has none, and says so (null) rather than guessing.
 *
 * Built by {@see WeeklyReportKpis}; `UnitKpis::fromArray()` reads the KPI keys of this shape too,
 * so a roll-up (5.7) can sum children's snapshots without caring about the delta.
 */
final readonly class ReportSnapshot
{
    private const KEYS = ['strength', 'rc_30d', 'sp_30d', 'at_risk', 'on_loa', 'makeups', 'delta'];

    private const DELTA_KEYS = ['strength', 'rc_30d_bp', 'sp_30d_bp', 'at_risk', 'on_loa', 'makeups'];

    /**
     * @param  list<int>  $missingChildren  a roll-up's direct children that had not sent when
     *                                      this was taken (5.7); always empty for a section.
     */
    public function __construct(
        public UnitKpis $kpis,
        public ?KpiDelta $delta,
        public array $missingChildren = [],
        public ?int $headcount = null,
    ) {
        if ($headcount !== null && $headcount < 0) {
            throw new InvalidArgumentException("headcount must not be negative; got {$headcount}.");
        }
    }

    /**
     * @return array{strength: int, rc_30d: array{numerator: int, denominator: int}, sp_30d: array{numerator: int, denominator: int}, at_risk: int, on_loa: int, makeups: int, delta: array{strength: int, rc_30d_bp: ?int, sp_30d_bp: ?int, at_risk: int, on_loa: int, makeups: int}|null, missing_children: list<int>, headcount?: int}
     */
    public function toArray(): array
    {
        return [
            ...$this->kpis->toArray(),
            'delta' => $this->delta?->toArray(),
            'missing_children' => $this->missingChildren,
            ...($this->headcount === null ? [] : ['headcount' => $this->headcount]),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     *
     * `missing_children` is optional on read: snapshots frozen before roll-ups could be sent carry
     * none, and read as complete. So is `headcount`: one frozen before #424 reads as none.
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
            self::missingChildren($snapshot['missing_children'] ?? []),
            self::headcount($snapshot['headcount'] ?? null),
        );
    }

    /**
     * @throws InvalidArgumentException when the stored value is neither absent nor a count.
     */
    private static function headcount(mixed $stored): ?int
    {
        if ($stored !== null && (! is_int($stored) || $stored < 0)) {
            throw new InvalidArgumentException('Not a report snapshot: headcount is not a count.');
        }

        return $stored;
    }

    /**
     * @return list<int>
     *
     * @throws InvalidArgumentException when the stored value is not a list of unit ids.
     */
    private static function missingChildren(mixed $stored): array
    {
        if (! is_array($stored) || ! array_is_list($stored) || array_filter($stored, fn ($id) => ! is_int($id)) !== []) {
            throw new InvalidArgumentException('Not a report snapshot: missing_children is not a list of unit ids.');
        }

        return $stored;
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
