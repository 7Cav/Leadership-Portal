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
     * is itself evidence of a crash that never reached either. A capture run (7.5) names the
     * practice it runs for in `$sectionPracticeId` (7.9).
     */
    public function open(string $source, int|string $triggeredBy, ?int $sectionPracticeId = null, ?int $unitId = null): SyncRun;

    /**
     * Close a run that finished processing. `errored > 0` means some rows were bad but the run
     * otherwise completed — recorded `partial`, not `failure`, since the good rows were still
     * committed. `$needsReview` counts rows left for a person to look at (the LOA sync, 4.2); it is
     * deliberately not part of `$errored`, so an unreadable post does not make the run `partial`. `$error` carries the per-row detail behind a partial run's `errored` count.
     * `$error` is cut to its first 2,000 characters, so a summary of many bad rows cannot overflow the column
     * and leave the run open.
     */
    public function complete(SyncRun $run, int $inserted, int $updated, int $inactivated, int $errored, ?string $error = null, int $needsReview = 0): void;

    /**
     * Save where a run has got to (`cursor`), while it is still going: the LOA sync saves it as it goes, so a run the
     * worker kills keeps its progress for the next one. Replaces what was saved before.
     *
     * @param  array<string, mixed>  $cursor
     */
    public function saveCursor(SyncRun $run, array $cursor): void;

    /**
     * The cursor the latest run of `$source` saved, whatever that run's outcome (a run that failed or was killed still
     * moved it as far as it got), or null when no run has saved one. Newest start first, `id` as the tiebreaker.
     *
     * @return array<string, mixed>|null
     */
    public function latestCursor(string $source): ?array;

    /**
     * Close a run that aborted before completing. Counts are left at their opened-run default of
     * zero: a failure means the write never happened, so there is nothing to count. `$error` is cut
     * the same way as {@see complete()}'s, so an over-long message still closes the run. `$internal` marks a run that
     * broke inside the portal (a database error, a bug), not on a source that could not answer.
     */
    public function fail(SyncRun $run, string $error, bool $internal = false): void;

    /**
     * Close every run of `$source` still `in_progress` as failed. For a source whose runs are serialized,
     * so one still open when a new run starts was killed (a worker timeout) before it could record itself.
     * A source serialized per practice (capture, 7.5) passes `$sectionPracticeId`, so only that practice's
     * runs are closed and a capture running for another practice is left alone.
     *
     * @return int how many were closed
     */
    public function failOrphaned(string $source, ?int $sectionPracticeId = null): int;

    /**
     * The most recent successful run for a source, for the roster view's (2.8) "last synced"
     * stamp — a `partial` run did write real data, but only a clean `success` is trustworthy
     * enough to call the cache current. `finished_at` is second-precision, so two runs closing in
     * the same second (a manual sync alongside a scheduled one, 2.4) need the `id` tiebreaker to
     * stay deterministic — the same convention the audit log's filtered read uses for its own
     * second-precision `created_at` column.
     */
    public function latestSuccessful(string $source): ?SyncRun;

    /**
     * The most recent *closed* run for a source, whatever its outcome — the roster view (2.8)
     * compares it with {@see latestSuccessful()} to flag a cache whose last attempt failed or
     * was partial (AD-4: the last good cache is shown, visibly stale). A run still
     * `in_progress` has no outcome yet and is skipped. Same `id` tiebreaker.
     */
    public function latestFinished(string $source): ?SyncRun;

    /**
     * The most recent *closed* run of `$source` for one practice, whatever its outcome — the rollcall
     * screen's "last tried" and failed state for a practice's capture (7.9, AD-20). A run still
     * `in_progress` (or left so by a crash) has no outcome and is skipped, so a retry in flight does
     * not hide the earlier failure. Newest start first, `id` as the tiebreaker.
     */
    public function latestForPractice(string $source, int $sectionPracticeId): ?SyncRun;

    /**
     * The most recent *closed* run of `$source` for one unit, whatever its outcome: the echelon leader's "did my
     * last sync work". An in-flight or crashed run has no outcome and is skipped. Newest start first, `id` as the
     * tiebreaker.
     */
    public function latestForUnit(string $source, int $unitId): ?SyncRun;

    /** Whether any run of `$source` exists for the practice, finished or not: a capture was attempted (#393). */
    public function existsForPractice(string $source, int $sectionPracticeId): bool;
}
