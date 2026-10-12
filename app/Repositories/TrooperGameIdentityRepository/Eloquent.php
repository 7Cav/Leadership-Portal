<?php

namespace App\Repositories\TrooperGameIdentityRepository;

use App\Models\GamePlatform;
use App\Models\TrooperGameIdentity;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\TrooperGameIdentityRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements TrooperGameIdentityRepository
{
    public function findByPlatformId(GamePlatform $platform, string $platformId): ?TrooperGameIdentity
    {
        return TrooperGameIdentity::query()
            ->where('platform', $platform->value)
            ->where('platform_id', $platformId)
            ->first();
    }

    public function forTrooper(int $trooperId): Collection
    {
        return TrooperGameIdentity::query()->where('trooper_id', $trooperId)->orderBy('id')->get();
    }

    public function link(int $trooperId, GamePlatform $platform, string $playerName, string $platformId): TrooperGameIdentity
    {
        $owner = $this->findByPlatformId($platform, $platformId);

        if ($owner !== null && $owner->trooper_id !== $trooperId) {
            throw GameIdentityLinkException::alreadyLinked($platform, $platformId);
        }

        try {
            return DB::transaction(fn (): TrooperGameIdentity => TrooperGameIdentity::query()->updateOrCreate(
                ['trooper_id' => $trooperId, 'platform' => $platform->value],
                ['player_name' => $playerName, 'platform_id' => $platformId],
            ));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent link claimed the same `(platform, platform_id)` between the check and the write.
            throw GameIdentityLinkException::alreadyLinked($platform, $platformId, $e);
        }
    }

    public function unlink(int $trooperId, GamePlatform $platform): bool
    {
        return TrooperGameIdentity::query()
            ->where('trooper_id', $trooperId)
            ->where('platform', $platform->value)
            ->delete() > 0;
    }
}
