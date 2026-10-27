<?php

namespace App\Repositories\GamePlayerDismissalRepository;

use App\Models\Game;
use App\Models\GamePlayerDismissal;
use App\Repositories\GamePlayerDismissalRepository;

class Eloquent implements GamePlayerDismissalRepository
{
    public function dismiss(Game $game, string $platformId, string $playerName, int $userId): GamePlayerDismissal
    {
        return GamePlayerDismissal::query()->updateOrCreate(
            ['game_id' => $game->id, 'platform_id' => $platformId],
            ['player_name' => $playerName, 'dismissed_by' => $userId],
        );
    }

    public function find(Game $game, string $platformId): ?GamePlayerDismissal
    {
        return GamePlayerDismissal::query()
            ->where('game_id', $game->id)
            ->where('platform_id', $platformId)
            ->first();
    }

    public function forget(Game $game, string $platformId): bool
    {
        return GamePlayerDismissal::query()
            ->where('game_id', $game->id)
            ->where('platform_id', $platformId)
            ->delete() > 0;
    }

    public function dismissedAmong(Game $game, array $platformIds): array
    {
        return $platformIds === [] ? [] : GamePlayerDismissal::query()
            ->where('game_id', $game->id)
            ->whereIn('platform_id', $platformIds)
            ->pluck('platform_id')
            ->all();
    }
}
