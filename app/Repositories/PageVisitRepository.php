<?php

namespace App\Repositories;

use App\Models\PageVisit;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `page_visits` (AD-2, #382): the Admin-only activity log of the pages people opened. Portal-owned
 * and short-lived (AD-22). The `RecordPageVisit` middleware is the one writer, after the response; the daily
 * `portal:prune-page-visits` command deletes what is older than {@see PageVisit::RETENTION_DAYS} days.
 */
interface PageVisitRepository
{
    /**
     * Store one visit. The caller has already cut the address down to what may be kept: a path and a query with
     * anything sensitive stripped, no longer than {@see PageVisit::MAX_URL_LENGTH}.
     */
    public function record(int $userId, string $url, ?string $actingAs, ?string $viewingAs, DateTimeInterface $at): void;

    /**
     * The activity log page's read: newest first, optionally one person's and a date range inclusive of both whole
     * days (`Y-m-d`, UTC). `dateTo` is compared against the start of the following day so the read stays on the
     * `visited_at` index. The `id` tiebreaker keeps paging stable for visits in the same second. `user` is loaded.
     *
     * @return LengthAwarePaginator<int, PageVisit>
     */
    public function filtered(?int $userId = null, ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 50): LengthAwarePaginator;

    /**
     * Everyone with at least one stored visit, by username, for the page's person filter.
     *
     * @return Collection<int, User>
     */
    public function users(): Collection;

    /**
     * Delete every visit from before `$cutoff`, `$chunk` rows at a time so the prune never holds one long lock on a
     * table every page view writes to. Returns how many were deleted.
     */
    public function pruneBefore(DateTimeInterface $cutoff, int $chunk = 1000): int;
}
