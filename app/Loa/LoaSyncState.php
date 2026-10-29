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
 * succeed, or the last good sync is older than {@see LoaProvenance::STALE_AFTER_HOURS}: the page is then
 * showing the last good cache. `neverSynced()` is the explicit state for a
 * portal that has no successful LOA sync yet, so a screen never shows a blank or an invented time.
 *
 * `backfilling` is set while the sync's first read of the year is still going back through older posts, a
 * few pages each run; `backfilledTo` is the oldest post it has reached so far. Recent leave is read first,
 * so the current weeks are right, but older weeks (the heatmap, the matrix) may miss leave until it is done.
 */
final readonly class LoaSyncState
{
    public function __construct(
        public string $source,
        public ?CarbonImmutable $lastSyncedAt,
        public bool $stale,
        public ?string $latestStatus,
        public ?CarbonImmutable $latestFinishedAt,
        public bool $backfilling = false,
        public ?CarbonImmutable $backfilledTo = null,
    ) {}

    public function neverSynced(): bool
    {
        return $this->lastSyncedAt === null;
    }

    /**
     * The Inertia prop shape for the LOA screens.
     *
     * @return array{source: string, lastSyncedAt: ?string, stale: bool, neverSynced: bool, latestStatus: ?string, latestFinishedAt: ?string, backfilling: bool, backfilledTo: ?string}
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
            'backfilling' => $this->backfilling,
            'backfilledTo' => $this->backfilledTo?->toIso8601String(),
        ];
    }
}
