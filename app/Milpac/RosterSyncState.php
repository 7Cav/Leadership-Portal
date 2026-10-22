<?php

namespace App\Milpac;

use App\Models\SyncRunStatus;
use Carbon\CarbonImmutable;

/**
 * Where the roster cache came from and how current it is (AD-20, AD-4): the source label every roster value renders
 * with, and the time of the last *successful* roster sync. Strength, ranks, billets and placements all come from this
 * cache, so every page that shows them reads this one state rather than judging freshness its own way.
 *
 * `lastSyncedAt` is a clean `success` run only; a `partial` run wrote real data but skipped rows, so it does not call
 * the cache current. `stale` is set when the latest finished run did not succeed, or the last good sync is older than
 * {@see RosterProvenance::STALE_AFTER_HOURS}: the page is then showing the last good copy. `neverSynced()` is the
 * explicit state for a portal with no successful roster sync yet, so a screen never shows a blank or an invented time.
 */
final readonly class RosterSyncState
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
     * The latest finished run when it did not succeed, the attempt the page warns about; null when the latest run
     * succeeded (a cache that is stale only by age has no failed attempt to name).
     *
     * @return array{status: string, at: ?string}|null
     */
    public function lastAttempt(): ?array
    {
        return $this->latestStatus === null || $this->latestStatus === SyncRunStatus::Success->value
            ? null
            : ['status' => $this->latestStatus, 'at' => $this->latestFinishedAt?->toIso8601String()];
    }

    /**
     * One word for the dashboard's freshness line: `failed` (the latest run failed), `never` (no run has succeeded
     * yet), `partial` (the latest run skipped rows), `stale` (the last good sync is too old) or `ok`.
     */
    public function state(): string
    {
        return match (true) {
            $this->latestStatus === SyncRunStatus::Failure->value => 'failed',
            $this->neverSynced() => 'never',
            $this->latestStatus === SyncRunStatus::Partial->value => 'partial',
            $this->stale => 'stale',
            default => 'ok',
        };
    }

    /**
     * The prop shape the roster and the milpac read.
     *
     * @return array{source: string, lastSuccessAt: ?string, stale: bool, lastAttempt: ?array{status: string, at: ?string}}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'lastSuccessAt' => $this->lastSyncedAt?->toIso8601String(),
            'stale' => $this->stale,
            'lastAttempt' => $this->lastAttempt(),
        ];
    }
}
