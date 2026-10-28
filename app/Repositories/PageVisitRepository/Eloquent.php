<?php

namespace App\Repositories\PageVisitRepository;

use App\Models\PageVisit;
use App\Models\User;
use App\Repositories\PageVisitRepository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class Eloquent implements PageVisitRepository
{
    public function record(int $userId, string $url, ?string $actingAs, ?string $viewingAs, DateTimeInterface $at): void
    {
        PageVisit::query()->create([
            'user_id' => $userId,
            'url' => mb_substr($url, 0, PageVisit::MAX_URL_LENGTH),
            'acting_as' => $actingAs === null ? null : mb_substr($actingAs, 0, 255),
            'viewing_as' => $viewingAs === null ? null : mb_substr($viewingAs, 0, 255),
            'visited_at' => $at,
        ]);
    }

    public function filtered(?int $userId = null, ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 50): LengthAwarePaginator
    {
        return PageVisit::query()
            ->with('user')
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->when($dateFrom !== null, fn ($query) => $query->where('visited_at', '>=', CarbonImmutable::parse($dateFrom, 'UTC')->startOfDay()))
            ->when($dateTo !== null, fn ($query) => $query->where('visited_at', '<', CarbonImmutable::parse($dateTo, 'UTC')->startOfDay()->addDay()))
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function users(): Collection
    {
        return User::query()
            ->whereIn('id', PageVisit::query()->select('user_id'))
            ->orderBy('username')
            ->get();
    }

    public function pruneBefore(DateTimeInterface $cutoff, int $chunk = 1000): int
    {
        $deleted = 0;

        // Ids first, then a delete by id: portable (SQLite has no DELETE ... LIMIT) and each delete is short.
        do {
            $ids = PageVisit::query()->where('visited_at', '<', $cutoff)->orderBy('id')->limit($chunk)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : PageVisit::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunk);

        return $deleted;
    }
}
