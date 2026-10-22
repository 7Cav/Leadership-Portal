<?php

namespace App\Milpac;

use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Repositories\SyncRunRepository;
use Carbon\CarbonImmutable;

/**
 * The one reader of the roster cache's source and freshness (AD-20), so Home, the roster, the milpac, rollcall and
 * the weekly report all say the same thing about it. The same rule as Loa\LoaProvenance: read-only over
 * `sync_runs`, never a writer of it (AD-2); the roster sync (`Sync/SyncRosterHandler`) is the only one.
 *
 * The source label is fixed by where the roster is read from, not stored per run.
 */
final class RosterProvenance
{
    /** What a value is labelled with when it came from the roster cache. */
    public const SOURCE_LABEL = 'milpac';

    /**
     * How old the last good sync may be before the cache is called stale even though nothing failed: the sync runs
     * hourly, so this allows several missed runs. Without it a scheduler that stopped or a dead worker would leave the
     * last run `success`, and the page fresh, for days.
     */
    public const STALE_AFTER_HOURS = 6;

    public function __construct(private readonly SyncRunRepository $syncRuns) {}

    public function current(): RosterSyncState
    {
        $latest = $this->syncRuns->latestFinished(SyncRun::SOURCE_ROSTER);
        $lastGood = $this->syncRuns->latestSuccessful(SyncRun::SOURCE_ROSTER);

        $lastSyncedAt = $lastGood?->finished_at ? CarbonImmutable::instance($lastGood->finished_at) : null;
        $tooOld = $lastSyncedAt !== null && $lastSyncedAt->lessThan(CarbonImmutable::now()->subHours(self::STALE_AFTER_HOURS));

        return new RosterSyncState(
            source: self::SOURCE_LABEL,
            lastSyncedAt: $lastSyncedAt,
            stale: $tooOld || ($latest !== null && $latest->status !== SyncRunStatus::Success),
            latestStatus: $latest?->status->value,
            latestFinishedAt: $latest?->finished_at ? CarbonImmutable::instance($latest->finished_at) : null,
        );
    }
}
