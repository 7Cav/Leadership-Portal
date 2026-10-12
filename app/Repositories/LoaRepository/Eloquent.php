<?php

namespace App\Repositories\LoaRepository;

use App\Models\Loa;
use App\Repositories\LoaRepository;
use Illuminate\Database\Eloquent\Collection;

class Eloquent implements LoaRepository
{
    public function findByForumThreadId(int $forumThreadId): ?Loa
    {
        return Loa::query()->where('forum_thread_id', $forumThreadId)->first();
    }

    public function forTrooper(int $trooperId): Collection
    {
        return Loa::query()
            ->where('trooper_id', $trooperId)
            ->orderByRaw('starts_at is null')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();
    }
}
