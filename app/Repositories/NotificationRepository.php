<?php

namespace App\Repositories;

use DateTimeInterface;

/**
 * Sole DB access for `notifications` (AD-2): the bell in the header. Laravel's database channel writes the rows when a
 * notification is sent; this reads and marks them. Every method takes the user whose notifications it touches and
 * never reaches anyone else's, so a caller passes the signed-in user and an id from them can only ever hit their own.
 */
interface NotificationRepository
{
    /**
     * The user's latest notifications, newest first: `id`, `message`, `url`, `read` and `createdAt` (ISO 8601 UTC).
     *
     * @return list<array{id: string, message: string, url: ?string, read: bool, createdAt: string}>
     */
    public function recentFor(int $userId, int $limit = 20): array;

    public function unreadCountFor(int $userId): int;

    /** Mark one of the user's notifications read. False when the user has none with that id. */
    public function markRead(int $userId, string $id): bool;

    /** Mark every unread notification of the user read; how many were. */
    public function markAllRead(int $userId): int;

    /**
     * Delete every notification, anyone's, read before `$cutoff`, `$chunk` rows at a time; unread ones stay. How many
     * were deleted. Run by `portal:prune-notifications`, the one method that is not about a single user.
     */
    public function pruneReadBefore(DateTimeInterface $cutoff, int $chunk = 1000): int;
}
