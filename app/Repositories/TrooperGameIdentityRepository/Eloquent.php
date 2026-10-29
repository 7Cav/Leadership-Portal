<?php

namespace App\Repositories\TrooperGameIdentityRepository;

use App\Models\Game;
use App\Models\TrooperGameIdentity;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\TrooperGameIdentityRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements TrooperGameIdentityRepository
{
    public function findByPlatformId(Game $game, string $platformId): ?TrooperGameIdentity
    {
        return TrooperGameIdentity::query()
            ->where('game_id', $game->id)
            ->where('platform_id', $platformId)
            ->first();
    }

    public function findByPlatformIds(Game $game, array $platformIds): Collection
    {
        $rows = new Collection;

        foreach (array_chunk(array_values(array_unique($platformIds)), 500) as $chunk) {
            $rows = $rows->concat(TrooperGameIdentity::query()->where('game_id', $game->id)->whereIn('platform_id', $chunk)->orderBy('id')->get());
        }

        return $rows;
    }

    public function forTrooper(int $trooperId): Collection
    {
        return TrooperGameIdentity::query()->with('game')->where('trooper_id', $trooperId)->orderBy('id')->get();
    }

    public function forTroopers(array $trooperIds): Collection
    {
        return $trooperIds === []
            ? new Collection
            : TrooperGameIdentity::query()->with('game')->whereIn('trooper_id', $trooperIds)->orderBy('id')->get();
    }

    public function all(): Collection
    {
        return TrooperGameIdentity::query()->with(['game', 'trooper'])->orderBy('id')->get();
    }

    public function lockForTrooper(int $trooperId, Game $game): ?TrooperGameIdentity
    {
        return TrooperGameIdentity::query()
            ->where('trooper_id', $trooperId)
            ->where('game_id', $game->id)
            ->lockForUpdate()
            ->first();
    }

    public function link(int $trooperId, Game $game, string $playerName, string $platformId, string $idKind): TrooperGameIdentity
    {
        $owner = $this->findByPlatformId($game, $platformId);

        if ($owner !== null && $owner->trooper_id !== $trooperId) {
            throw GameIdentityLinkException::alreadyLinked($game, $platformId);
        }

        try {
            return DB::transaction(fn (): TrooperGameIdentity => TrooperGameIdentity::query()->updateOrCreate(
                ['trooper_id' => $trooperId, 'game_id' => $game->id],
                ['player_name' => $playerName, 'platform_id' => $platformId, 'id_kind' => $idKind],
            ));
        } catch (UniqueConstraintViolationException $e) {
            // Only a concurrent claim by a *different* trooper is a rejected link; any other
            // collision (e.g. the same trooper double-submitting) is not ours to relabel.
            $owner = $this->findByPlatformId($game, $platformId);

            if ($owner !== null && $owner->trooper_id !== $trooperId) {
                throw GameIdentityLinkException::alreadyLinked($game, $platformId, $e);
            }

            throw $e;
        }
    }

    public function kindsInUse(Game $game): array
    {
        return TrooperGameIdentity::query()->where('game_id', $game->id)->distinct()->pluck('id_kind')->values()->all();
    }

    public function unlink(int $trooperId, Game $game): bool
    {
        return TrooperGameIdentity::query()
            ->where('trooper_id', $trooperId)
            ->where('game_id', $game->id)
            ->delete() > 0;
    }
}
