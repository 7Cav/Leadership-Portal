<?php

namespace App\Rollcall;

use App\Models\Game;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Repositories\GameRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitGame;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

/**
 * Which serving troopers have no identity linked for the game their unit plays, so the capture cannot see them. The game
 * is the one {@see UnitGame} works out for the trooper's own unit (its own, else the nearest unit above's). A trooper
 * counts as missing when they hold no `trooper_game_identities` row on that game: an identity is one per game, and any
 * of the game's ID kinds (Steam, Epic) counts, since the game's `(game_id, platform_id)` key is what the capture
 * matches on, whatever kind the ID was entered as.
 *
 * Left out: a trooper whose unit plays no game, a retired game (nobody can link one any more), and a trooper placed on a
 * group (ELOA, Reservists, New Recruits, a staff department) rather than a line unit. Read-only, and a fixed number of
 * queries however many troopers are asked about: the tree, the games and the identities, each once.
 */
class MissingGameIdentities
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly GameRepository $games,
        private readonly TrooperGameIdentityRepository $identities,
    ) {}

    /**
     * The game each of `$troopers` is missing an identity for, by trooper id; a trooper not missing one is absent.
     *
     * @param  Collection<int, Trooper>  $troopers
     * @return array<int, Game>
     */
    public function forTroopers(Collection $troopers): array
    {
        $serving = $troopers->filter(fn (Trooper $trooper) => $trooper->status->isServing() && $trooper->primary_section_id !== null);

        if ($serving->isEmpty()) {
            return [];
        }

        $tree = $this->units->tree();
        $unitGames = UnitGame::resolve($tree);
        $line = $tree->filter(fn (Unit $unit) => $unit->isLine())->keyBy('id');
        $games = $this->games->all()->reject(fn (Game $game) => $game->isRetired())->keyBy('id');

        // The game each trooper should be linked on, when there is one.
        $expected = $serving->mapWithKeys(function (Trooper $trooper) use ($unitGames, $line, $games) {
            $unitId = (int) $trooper->primary_section_id;
            $game = $line->has($unitId) && isset($unitGames[$unitId]) ? $games->get($unitGames[$unitId]->gameId) : null;

            return [$trooper->id => $game];
        })->filter();

        if ($expected->isEmpty()) {
            return [];
        }

        $linked = $this->identities->forTroopers($expected->keys()->all())
            ->mapWithKeys(fn (TrooperGameIdentity $identity) => ["{$identity->trooper_id}|{$identity->game_id}" => true]);

        return $expected->reject(fn (Game $game, int $trooperId) => $linked->has("{$trooperId}|{$game->id}"))->all();
    }

    /**
     * {@see forTroopers()} for everyone serving and placed in `$unitIds`.
     *
     * @param  iterable<int>  $unitIds
     * @return array<int, Game>
     */
    public function placedIn(iterable $unitIds): array
    {
        return $this->forTroopers($this->troopers->placedInAny($unitIds));
    }

    /**
     * {@see forTroopers()} for everyone placed in `$unitIds`, grouped by game for an alert: each game with the troopers
     * missing it, sorted by name. Empty when nobody is missing one.
     *
     * @param  iterable<int>  $unitIds
     * @return list<array{gameId: int, game: string, count: int, troopers: list<array{id: int, name: string}>}>
     */
    public function summaryFor(iterable $unitIds): array
    {
        $placed = $this->troopers->placedInAny($unitIds);
        $missing = $this->forTroopers($placed);
        $byId = $placed->keyBy('id');

        return collect($missing)
            ->groupBy(fn (Game $game) => $game->id, preserveKeys: true)
            ->map(fn (Collection $group) => [
                'gameId' => $group->first()->id,
                'game' => $group->first()->name,
                'count' => $group->count(),
                'troopers' => $group->keys()
                    ->map(fn (int $id) => ['id' => $id, 'name' => $byId->get($id)->display_name])
                    ->sortBy(fn (array $row) => mb_strtolower($row['name']))
                    ->values()
                    ->all(),
            ])
            ->sortBy('game')
            ->values()
            ->all();
    }
}
