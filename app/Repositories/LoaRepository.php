<?php

namespace App\Repositories;

use App\Models\Loa;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Sole DB access for the `loas` cache (AD-2, AD-22). The LOA sync (4.2) is its one writer, through
 * {@see sync()}; every other method here reads.
 */
interface LoaRepository
{
    /**
     * The row for one forum thread, the key the sync upserts on.
     */
    public function findByForumThreadId(int $forumThreadId): ?Loa;

    /**
     * Every leave row for a trooper, newest first: by start date, rows with no start (a
     * `needs_review` row) last, then by id so the order never shuffles between requests.
     *
     * @return Collection<int, Loa>
     */
    public function forTrooper(int $trooperId): Collection;

    /**
     * The leave rows that may bear on these troopers in `$weeks` consecutive Cav weeks from `$firstWeekStart`
     * (the Saturday 00:00 UTC that opens the first), for the LOA coverage resolver (4.4, 4.8).
     *
     * - A cleanly read row whose window touches any day of the range: it starts on or before its last day
     *   and ends on or after its first, or has no end at all.
     * - A row that needs review, or a row with no start date, posted on the forum from the week before the
     *   range up to the range's end (`WeekOf`, AD-11). The parser keeps no dates for it, so the post date is
     *   the only date it has, and it shields the trooper for the week it was posted in and the next, then
     *   stops: a broken post cannot keep someone out of the KPIs for good, and the first sync's backfill of
     *   old malformed posts does not cover anyone from that sync on. A row with no `posted_at` (stored before
     *   the column existed, and not read again since) is placed by `created_at` instead.
     *
     * A superset: it says which rows to look at, the resolver decides which weeks each one touches. Rows with
     * no trooper, and withdrawn rows, never match. Read-only.
     *
     * @param  list<int>  $trooperIds
     * @return Collection<int, Loa>
     *
     * @throws InvalidArgumentException when `$firstWeekStart` is not a Saturday 00:00 UTC.
     */
    public function touchingWeeks(array $trooperIds, CarbonImmutable $firstWeekStart, int $weeks): Collection;

    /**
     * Which of these forum threads are settled history: a row that read cleanly and whose leave ended before
     * `$endedBefore`. A full sync skips them, since an old, finished leave is not worth a forum request to
     * re-read. An ongoing or open-ended leave, a row that needs review, and a thread with no row are never
     * settled.
     *
     * @param  list<int>  $forumThreadIds
     * @return list<int>
     */
    public function settledThreadIds(array $forumThreadIds, CarbonImmutable $endedBefore): array;

    /**
     * Apply one sync run as a single transaction, so a failure part-way leaves the cache exactly as it was.
     *
     * Each write is an upsert keyed by `forum_thread_id` onto the row's own id, never a truncate and
     * reinsert. A row that was read cleanly before and is not now flips to `needs_review` with its dates
     * cleared, rather than keeping stale ones. A row an ELOA return has already ended keeps that end, so
     * re-reading the ELOA thread (which still says "no end") cannot reopen the leave.
     *
     * Then each return ends the trooper's latest open ELOA that started on or before it, on the return's
     * date. A return that was already applied is skipped, and one with no open ELOA to end is counted, not
     * stored.
     *
     * A write also clears `withdrawn_at`: a thread read again is there again.
     *
     * @param  list<LoaWrite>  $writes
     * @param  list<LoaReturn>  $returns
     * @return array{created: int, updated: int, closed: int, unmatchedReturns: int}
     */
    public function sync(array $writes, array $returns): array;

    /**
     * After a complete full run: every row from one of `$forumNodeIds` posted on or after `$postedFrom` whose thread
     * is not in `$seenThreadIds` is marked withdrawn at `$at` (deleted, hidden or moved out of the leave sections), and
     * any withdrawn row whose thread is in `$seenThreadIds` is put back. Rows are never deleted, so the history stays.
     * A row with no `posted_at` is left alone, since it cannot be placed inside the window. One transaction.
     *
     * @param  list<int>  $forumNodeIds  the sections the run read in full
     * @param  list<int>  $seenThreadIds  the visible threads it found there
     * @return array{withdrawn: int, restored: int}
     */
    public function withdrawUnseen(array $forumNodeIds, array $seenThreadIds, CarbonImmutable $postedFrom, CarbonImmutable $at): array;
}
