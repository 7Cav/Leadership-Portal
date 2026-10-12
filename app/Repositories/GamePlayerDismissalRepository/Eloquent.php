<?php

namespace App\Repositories\GamePlayerDismissalRepository;

use App\Models\GamePlatform;
use App\Models\GamePlayerDismissal;
use App\Repositories\GamePlayerDismissalRepository;

class Eloquent implements GamePlayerDismissalRepository
{
    public function dismiss(GamePlatform $platform, string $platformId, string $playerName, int $userId): GamePlayerDismissal
    {
        return GamePlayerDismissal::query()->updateOrCreate(
            ['platform' => $platform->value, 'platform_id' => $platformId],
            ['player_name' => $playerName, 'dismissed_by' => $userId],
        );
    }

    public function forget(GamePlatform $platform, string $platformId): bool
    {
        return GamePlayerDismissal::query()
            ->where('platform', $platform->value)
            ->where('platform_id', $platformId)
            ->delete() > 0;
    }

    public function dismissedAmong(GamePlatform $platform, array $platformIds): array
    {
        return $platformIds === [] ? [] : GamePlayerDismissal::query()
            ->where('platform', $platform->value)
            ->whereIn('platform_id', $platformIds)
            ->pluck('platform_id')
            ->all();
    }
}
