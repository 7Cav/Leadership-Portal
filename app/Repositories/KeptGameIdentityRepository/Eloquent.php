<?php

namespace App\Repositories\KeptGameIdentityRepository;

use App\Models\Game;
use App\Models\Gamertag;
use App\Models\IdentitySource;
use App\Models\KeptGameIdentity;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Repositories\KeptGameIdentityRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Eloquent implements KeptGameIdentityRepository
{
    public function all(): Collection
    {
        return KeptGameIdentity::query()->with(['game', 'creator'])->orderBy('cav_name_key')->orderBy('game_id')->get();
    }

    public function find(int $id): ?KeptGameIdentity
    {
        return KeptGameIdentity::query()->find($id);
    }

    public function forNames(array $keys): Collection
    {
        return $keys === [] ? new Collection : KeptGameIdentity::query()->whereIn('cav_name_key', array_values(array_unique($keys)))->get();
    }

    public function findByPlatformId(Game $game, string $platformId): ?KeptGameIdentity
    {
        return KeptGameIdentity::query()->where('game_id', $game->id)->where('platform_id', $platformId)->first();
    }

    public function lockForName(string $cavName, Game $game): ?KeptGameIdentity
    {
        return KeptGameIdentity::query()->where('cav_name_key', KeptGameIdentity::key($cavName))->where('game_id', $game->id)->lockForUpdate()->first();
    }

    public function keep(string $cavName, Game $game, string $platformId, string $idKind, ?Gamertag $gamertag, int $createdBy): KeptGameIdentity
    {
        return KeptGameIdentity::query()->updateOrCreate(
            ['cav_name_key' => KeptGameIdentity::key($cavName), 'game_id' => $game->id],
            ['cav_name' => trim($cavName), 'platform_id' => $platformId, 'id_kind' => $idKind, 'gamertag' => $gamertag?->value,
                'gamertag_from_server' => $gamertag?->value !== null && $gamertag->fromServer, 'source' => IdentitySource::Imported, 'created_by' => $createdBy, 'conflict' => null],
        );
    }

    public function delete(KeptGameIdentity $kept): void
    {
        $kept->delete();
    }

    public function attachRejoined(): array
    {
        $kept = KeptGameIdentity::query()->whereIn('cav_name_key', Trooper::query()->select(DB::raw('lower(username)')))->orderBy('id')->get();
        $attached = 0;
        $conflicts = 0;

        if ($kept->isEmpty()) {
            return ['attached' => 0, 'conflicts' => 0];
        }

        $troopers = Trooper::query()->whereIn(DB::raw('lower(username)'), $kept->pluck('cav_name_key')->unique()->values()->all())->get(['id', 'username'])
            ->groupBy(fn (Trooper $trooper) => mb_strtolower($trooper->username));

        DB::transaction(function () use ($kept, $troopers, &$attached, &$conflicts) {
            foreach ($kept as $row) {
                $matches = $troopers->get($row->cav_name_key) ?? collect();
                $trooperId = $matches->count() === 1 ? (int) $matches->first()->id : null;
                $owner = TrooperGameIdentity::query()->where('game_id', $row->game_id)->where('platform_id', $row->platform_id)->lockForUpdate()->first();
                $conflict = match (true) {
                    $trooperId === null => 'More than one trooper has this name.',
                    $owner !== null && $owner->trooper_id !== $trooperId => 'Another trooper holds this ID on this game.',
                    $owner === null && TrooperGameIdentity::query()->where('trooper_id', $trooperId)->where('game_id', $row->game_id)->exists() => 'The trooper already has an identity on this game.',
                    default => null,
                };

                if ($conflict !== null) {
                    if ($row->conflict !== $conflict) {
                        $row->update(['conflict' => $conflict]);
                        Log::warning('A kept game identity could not be moved onto its trooper.', ['kept' => $row->id, 'reason' => $conflict]);
                    }
                    $conflicts++;

                    continue;
                }

                if ($owner === null) {
                    TrooperGameIdentity::query()->create(['trooper_id' => $trooperId, 'game_id' => $row->game_id, 'platform_id' => $row->platform_id, 'id_kind' => $row->id_kind,
                        'gamertag' => $row->gamertag, 'gamertag_from_server' => $row->gamertag_from_server, 'source' => IdentitySource::Imported]);
                }

                Log::info('Moved a kept game identity onto its trooper.', ['kept' => $row->id, 'trooper' => $trooperId, 'game' => $row->game_id, 'platform_id' => $row->platform_id]);
                $row->delete();
                $attached++;
            }
        });

        return ['attached' => $attached, 'conflicts' => $conflicts];
    }
}
