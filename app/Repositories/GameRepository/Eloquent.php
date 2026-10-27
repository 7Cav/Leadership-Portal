<?php

namespace App\Repositories\GameRepository;

use App\Models\Game;
use App\Models\GamePlayerDismissal;
use App\Models\PracticeCapturePlayer;
use App\Models\TelemetryInterface;
use App\Models\TrooperGameIdentity;
use App\Repositories\GameInUseException;
use App\Repositories\GameRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements GameRepository
{
    /** The models whose rows name a game: one in use by any of them is retired, never deleted. */
    private const USERS = [TrooperGameIdentity::class, PracticeCapturePlayer::class, GamePlayerDismissal::class];

    public function all(): Collection
    {
        return Game::query()->orderBy('id')->get();
    }

    public function find(int $id): ?Game
    {
        return Game::query()->find($id);
    }

    public function findBySlug(string $slug): ?Game
    {
        return Game::query()->where('slug', $slug)->first();
    }

    public function create(string $slug, string $name, string $idLabel, TelemetryInterface $interface): Game
    {
        return Game::query()->create(['slug' => $slug, 'name' => $name, 'id_label' => $idLabel, 'interface' => $interface]);
    }

    public function update(Game $game, string $name, string $idLabel, TelemetryInterface $interface): Game
    {
        $game->update(['name' => $name, 'id_label' => $idLabel, 'interface' => $interface]);

        return $game;
    }

    public function setRetired(Game $game, bool $retired): Game
    {
        if ($game->isRetired() !== $retired) {
            $game->update(['retired_at' => $retired ? now() : null]);
        }

        return $game;
    }

    public function inUseIds(): array
    {
        return collect(self::USERS)
            ->flatMap(fn (string $model) => $model::query()->distinct()->pluck('game_id'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function delete(Game $game): Game
    {
        return DB::transaction(function () use ($game) {
            Game::query()->whereKey($game->id)->lockForUpdate()->first();

            foreach (self::USERS as $model) {
                if ($model::query()->where('game_id', $game->id)->exists()) {
                    throw GameInUseException::for($game);
                }
            }

            try {
                $game->delete();
            } catch (QueryException $e) {
                // A row naming the game landed after the check: the foreign key refuses the delete.
                throw GameInUseException::for($game, $e);
            }

            return $game;
        });
    }
}
