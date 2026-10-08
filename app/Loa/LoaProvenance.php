<?php

namespace App\Loa;

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
    /** The `sync_runs.source` value the LOA sync records under. */
    public const SYNC_SOURCE = 'loa';

    /** What a value is labelled with when it came from the cache. */
    public const SOURCE_LABEL = 'Forum thread';

    public function __construct(private readonly SyncRunRepository $syncRuns) {}

    public function current(): LoaSyncState
    {
        $latest = $this->syncRuns->latestFinished(self::SYNC_SOURCE);
        $lastGood = $this->syncRuns->latestSuccessful(self::SYNC_SOURCE);

        return new LoaSyncState(
            source: self::SOURCE_LABEL,
            lastSyncedAt: $lastGood?->finished_at ? CarbonImmutable::instance($lastGood->finished_at) : null,
            stale: $latest !== null && $latest->status !== SyncRunStatus::Success,
            latestStatus: $latest?->status->value,
            latestFinishedAt: $latest?->finished_at ? CarbonImmutable::instance($latest->finished_at) : null,
        );
    }
}
