<?php

namespace App\Loa;

use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Repositories\SyncRunRepository;
use Carbon\CarbonImmutable;

/**
 * The one reader of the LOA cache's source and freshness (AD-20, Story 4.3), so the badge, the section
 * overview and the trooper page all say the same thing about it. Read-only over `sync_runs`: it never
 * writes `sync_runs` or `loas` (AD-2); the LOA sync (4.2) is the only writer of both.
 *
 * The source label is fixed by where leave is read from, the forum thread, not stored per run.
 */
final class LoaProvenance
{
    /** What a value is labelled with when it came from the cache. */
    public const SOURCE_LABEL = 'Forum thread';

    /**
     * How old the last good sync may be before the cache is called stale even though nothing failed: the
     * sync runs hourly, so this allows several missed runs. Without it a scheduler that stopped, an
     * unconfigured sync or a dead worker would leave the last run `success`, and the page fresh, for days.
     */
    public const STALE_AFTER_HOURS = 6;

    public function __construct(private readonly SyncRunRepository $syncRuns) {}

    public function current(): LoaSyncState
    {
        $latest = $this->syncRuns->latestFinished(SyncRun::SOURCE_LOA);
        $lastGood = $this->syncRuns->latestSuccessful(SyncRun::SOURCE_LOA);

        $lastSyncedAt = $lastGood?->finished_at ? CarbonImmutable::instance($lastGood->finished_at) : null;
        $tooOld = $lastSyncedAt !== null && $lastSyncedAt->lessThan(CarbonImmutable::now()->subHours(self::STALE_AFTER_HOURS));

        // How far the sync's first read of the year has got, from the cursor it saves as it goes (SyncLoasHandler).
        $cursor = $this->syncRuns->latestCursor(SyncRun::SOURCE_LOA);
        $backfilling = (bool) ($cursor['backfilling'] ?? false);
        $reachedBack = $backfilling && is_string($cursor['reachedBack'] ?? null) ? CarbonImmutable::parse($cursor['reachedBack']) : null;

        return new LoaSyncState(
            source: self::SOURCE_LABEL,
            lastSyncedAt: $lastSyncedAt,
            stale: $tooOld || ($latest !== null && $latest->status !== SyncRunStatus::Success),
            latestStatus: $latest?->status->value,
            latestFinishedAt: $latest?->finished_at ? CarbonImmutable::instance($latest->finished_at) : null,
            backfilling: $backfilling,
            backfilledTo: $reachedBack,
        );
    }
}
