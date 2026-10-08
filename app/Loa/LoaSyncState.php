<?php

namespace App\Loa;

use Carbon\CarbonImmutable;

/**
 * Where the LOA cache came from and how current it is (AD-20, AD-4): the source label every LOA value
 * renders with, and the time of the last *successful* LOA sync. A screen shows this beside any LOA value,
 * so a stale cache is never mistaken for current truth.
 *
 * `lastSyncedAt` is a clean `success` run only; a `partial` run did write real data but left threads
 * unread, so it does not call the cache current. `stale` is set when the latest finished run did not
 * succeed: the page is then showing the last good cache. `neverSynced()` is the explicit state for a
 * portal that has no successful LOA sync yet, so a screen never shows a blank or an invented time.
 */
final readonly class LoaSyncState
{
    public function __construct(
        public string $source,
        public ?CarbonImmutable $lastSyncedAt,
        public bool $stale,
        public ?string $latestStatus,
        public ?CarbonImmutable $latestFinishedAt,
    ) {}

    public function neverSynced(): bool
    {
        return $this->lastSyncedAt === null;
    }

    /**
     * The Inertia prop shape, the same keys the roster page (2.8) reads, plus `neverSynced`.
     *
     * @return array{source: string, lastSyncedAt: ?string, stale: bool, neverSynced: bool, latestStatus: ?string, latestFinishedAt: ?string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'lastSyncedAt' => $this->lastSyncedAt?->toIso8601String(),
            'stale' => $this->stale,
            'neverSynced' => $this->neverSynced(),
            'latestStatus' => $this->latestStatus,
            'latestFinishedAt' => $this->latestFinishedAt?->toIso8601String(),
        ];
    }
}
