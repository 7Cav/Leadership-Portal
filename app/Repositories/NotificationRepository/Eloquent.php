<?php

namespace App\Repositories\NotificationRepository;

use App\Models\User;
use App\Repositories\NotificationRepository;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

class Eloquent implements NotificationRepository
{
    public function recentFor(int $userId, int $limit = 20): array
    {
        return $this->of($userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (DatabaseNotification $row) => [
                'id' => (string) $row->id,
                'message' => (string) ($row->data['message'] ?? ''),
                'url' => isset($row->data['url']) && is_string($row->data['url']) ? $row->data['url'] : null,
                'read' => $row->read_at !== null,
                'createdAt' => $row->created_at->toIso8601ZuluString(),
            ])
            ->values()
            ->all();
    }

    public function unreadCountFor(int $userId): int
    {
        return $this->of($userId)->whereNull('read_at')->count();
    }

    public function markRead(int $userId, string $id): bool
    {
        $row = $this->of($userId)->whereKey($id)->first();

        if ($row === null) {
            return false;
        }

        $row->markAsRead();

        return true;
    }

    public function markAllRead(int $userId): int
    {
        return $this->of($userId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function pruneReadBefore(DateTimeInterface $cutoff, int $chunk = 1000): int
    {
        $deleted = 0;

        // Ids first, then a delete by id: portable (SQLite has no DELETE ... LIMIT) and each delete is short.
        do {
            $ids = DatabaseNotification::query()->whereNotNull('read_at')->where('read_at', '<', $cutoff)->orderBy('id')->limit($chunk)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DatabaseNotification::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunk);

        return $deleted;
    }

    /** @return Builder<DatabaseNotification> */
    private function of(int $userId): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->where('notifiable_id', $userId);
    }
}
