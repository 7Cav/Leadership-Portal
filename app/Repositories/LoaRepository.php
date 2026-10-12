<?php

namespace App\Repositories;

use App\Models\Loa;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sole DB access for the `loas` cache (AD-2, AD-22). Reads only for now: the LOA sync (4.2) is the
 * one writer and adds its upsert here when it lands, so this story ships no way to write a row.
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
}
