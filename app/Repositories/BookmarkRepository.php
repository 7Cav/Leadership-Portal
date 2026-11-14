<?php

namespace App\Repositories;

use App\Models\Bookmark;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sole DB access for `bookmarks` (AD-2): the pages a person keeps in the sidebar's Bookmarks section. Every method
 * takes the user whose bookmarks it touches and never reaches anyone else's, so an id from another person's list is
 * treated like one that does not exist. A personal UI preference: nothing here is audited.
 */
interface BookmarkRepository
{
    /**
     * The user's bookmarks in their sidebar order.
     *
     * @return Collection<int, Bookmark>
     */
    public function forUser(int $userId): Collection;

    public function countFor(int $userId): int;

    /**
     * Add a bookmark at the end of the user's list. Null when the user already has this page (`params` is the canonical
     * text, {@see Bookmark::encodeParams()}), or when the list already holds `$limit`.
     */
    public function add(int $userId, string $route, string $params, string $label, int $limit): ?Bookmark;

    /** Delete one of the user's bookmarks. False when the user has none with that id. */
    public function remove(int $userId, int $id): bool;

    /**
     * Delete these of the user's bookmarks (the ones they can no longer open); how many were.
     *
     * @param  list<int>  $ids
     */
    public function removeMany(int $userId, array $ids): int;

    /**
     * Swap one of the user's bookmarks with its neighbour above (`$up`) or below. False when the user has none with that
     * id; true, changing nothing, when it is already first or last.
     */
    public function move(int $userId, int $id, bool $up): bool;
}
