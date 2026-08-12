<?php

namespace App\Repositories;

use App\Models\SyncRun;

/**
 * Sole DB access for `sync_runs` (AD-2, AD-22). Source-agnostic: `$source` is a plain string
 * (`roster` today) so the Epic 4 LOA sync can write `source = loa` rows through the same methods.
 */
interface SyncRunRepository
{
    /**
     * Open a run: `status = in_progress`, `started_at = now()`. The caller closes it with
     * {@see complete()} on success or {@see fail()} on a thrown error — a run left `in_progress`
     * is itself evidence of a crash that never reached either.
     */
    public function open(string $source, int|string $triggeredBy): SyncRun;

    /**
     * Close a run that finished processing. `errored > 0` means some rows were bad but the run
     * otherwise completed — recorded `partial`, not `failure`, since the good rows were still
     * committed. `$error` carries the per-row detail behind a partial run's `errored` count.
     */
    public function complete(SyncRun $run, int $inserted, int $updated, int $inactivated, int $errored, ?string $error = null): void;

    /**
     * Close a run that aborted before completing. Counts are left at their opened-run default of
     * zero: a failure means the write never happened, so there is nothing to count.
     */
    public function fail(SyncRun $run, string $error): void;

    /**
     * The most recent successful run for a source, for the roster view's (2.8) "last synced"
     * stamp — a `partial` run did write real data, but only a clean `success` is trustworthy
     * enough to call the cache current. `finished_at` is second-precision, so two runs closing in
     * the same second (a manual sync alongside a scheduled one, 2.4) need the `id` tiebreaker to
     * stay deterministic — the same convention the audit log's filtered read uses for its own
     * second-precision `created_at` column.
     */
    public function latestSuccessful(string $source): ?SyncRun;
}
