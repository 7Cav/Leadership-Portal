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
     * The leave rows that bear on these troopers in one Cav week, for the LOA coverage resolver (4.4).
     * `$weekStart` is the Saturday 00:00 UTC that opens the week, and the week is its seven days.
     *
     * - A cleanly read row whose window touches any day of the week: it starts on or before the Friday and
     *   ends on or after the Saturday, or has no end at all.
     * - A row that needs review, or a row with no start date, posted on the forum in this Cav week or the
     *   one before (`WeekOf`, AD-11). The parser keeps no dates for it, so the post date is the only date it
     *   has, and it shields the trooper for the week it was posted in and the next, then stops: a broken post
     *   cannot keep someone out of the KPIs for good, and the first sync's backfill of old malformed posts
     *   does not cover anyone from that sync on. A row with no `posted_at` (stored before the column existed,
     *   and not read again since) is placed by `created_at` instead.
     *
     * Rows with no trooper never match. Read-only; the resolver decides what the rows mean.
     *
     * @param  list<int>  $trooperIds
     * @return Collection<int, Loa>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function touchingWeek(array $trooperIds, CarbonImmutable $weekStart): Collection;

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
     * @param  list<LoaWrite>  $writes
     * @param  list<LoaReturn>  $returns
     * @return array{created: int, updated: int, closed: int, unmatchedReturns: int}
     */
    public function sync(array $writes, array $returns): array;
}
