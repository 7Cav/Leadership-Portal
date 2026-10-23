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

    public function forTroopers(array $trooperIds): Collection
    {
        return $trooperIds === []
            ? new Collection
            : TrooperGameIdentity::query()->whereIn('trooper_id', $trooperIds)->orderBy('id')->get();
    }

    public function lockForTrooper(int $trooperId, GamePlatform $platform): ?TrooperGameIdentity
    {
        return TrooperGameIdentity::query()
            ->where('trooper_id', $trooperId)
            ->where('platform', $platform->value)
            ->lockForUpdate()
            ->first();
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
            // Only a concurrent claim by a *different* trooper is a rejected link; any other
            // collision (e.g. the same trooper double-submitting) is not ours to relabel.
            $owner = $this->findByPlatformId($platform, $platformId);

            if ($owner !== null && $owner->trooper_id !== $trooperId) {
                throw GameIdentityLinkException::alreadyLinked($platform, $platformId, $e);
            }

            throw $e;
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
