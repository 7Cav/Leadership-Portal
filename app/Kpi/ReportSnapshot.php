<?php

namespace App\Kpi;

use InvalidArgumentException;

/**
 * What a weekly report freezes into `weekly_reports.kpis` on send (AD-12, 5.6): the six KPIs in
 * 5.1's storage shape, plus their week-over-week `delta` (null on a unit's first report). The
 * report screen reads a sent report back through `fromArray()` — never by recomputing it.
 *
 * Built by {@see WeeklyReportKpis}; `UnitKpis::fromArray()` reads the KPI keys of this shape too,
 * so a roll-up (5.7) can sum children's snapshots without caring about the delta.
 */
final readonly class ReportSnapshot
{
    public function __construct(
        public UnitKpis $kpis,
        public ?KpiDelta $delta,
    ) {}

    /**
     * @return array<string, mixed> `UnitKpis::toArray()` plus `delta` → `KpiDelta::toArray()` | null
     */
    public function toArray(): array
    {
        return [...$this->kpis->toArray(), 'delta' => $this->delta?->toArray()];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     *
     * @throws InvalidArgumentException when the stored array carries no `delta` key — a snapshot
     *                                  this class did not write, which must not pass as a first report.
     */
    public static function fromArray(array $snapshot): self
    {
        if (! array_key_exists('delta', $snapshot)) {
            throw new InvalidArgumentException('Not a report snapshot: the stored KPIs carry no delta key.');
        }

        return new self(
            UnitKpis::fromArray($snapshot),
            $snapshot['delta'] === null ? null : KpiDelta::fromArray($snapshot['delta']),
        );
    }
}
