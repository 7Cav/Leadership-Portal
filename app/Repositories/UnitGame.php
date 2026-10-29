<?php

namespace App\Repositories;

use App\Models\Unit;

/**
 * The game a unit plays (policy, 2026-10-29): its own `game_id`, else the game of the nearest unit above it that has
 * one. `$from` is that ancestor, or null when the game is the unit's own. Retired or not is the reader's call: Game
 * identities never opens on a retired game, while the capture still reads one.
 *
 * Pure logic over units already loaded, so the org page, Game identities and the capture work it out the same way
 * with no query per unit.
 */
final readonly class UnitGame
{
    public function __construct(
        public int $unitId,
        public int $gameId,
        public ?Unit $from,
    ) {}

    public function inherited(): bool
    {
        return $this->from !== null;
    }

    /**
     * Each unit's game, by unit id, walking `parent_id` up through `$units`. A unit with no game on its chain is absent.
     * Only ancestors among `$units` are seen, so pass the whole tree or the unit's whole chain.
     *
     * @param  iterable<Unit>  $units
     * @return array<int, UnitGame>
     */
    public static function resolve(iterable $units): array
    {
        $byId = [];
        foreach ($units as $unit) {
            $byId[$unit->id] = $unit;
        }

        // The unit whose own game each unit plays, by unit id; null for none. Filled as each chain is walked, so every
        // unit is visited once.
        $source = [];
        $sourceOf = function (int $id) use (&$sourceOf, &$source, $byId): ?Unit {
            if (array_key_exists($id, $source)) {
                return $source[$id];
            }

            $unit = $byId[$id] ?? null;
            $source[$id] = null;

            if ($unit === null) {
                return null;
            }

            return $source[$id] = $unit->game_id !== null ? $unit : ($unit->parent_id === null ? null : $sourceOf((int) $unit->parent_id));
        };

        $games = [];
        foreach ($byId as $id => $unit) {
            $from = $sourceOf($id);

            if ($from !== null) {
                $games[$id] = new self($id, (int) $from->game_id, $from->id === $id ? null : $from);
            }
        }

        return $games;
    }
}
