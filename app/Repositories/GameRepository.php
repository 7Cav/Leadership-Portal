<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\TelemetryInterface;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `games` (AD-2, #388). Portal-owned (AD-22): the audited `app/Commands/Games` Commands are its
 * one writer; everything that names a game (identities, captures, dismissals) reads it from here.
 */
interface GameRepository
{
    /**
     * Every game, retired ones included, in the order they were added — the hll, arma, wardogs order the migration
     * created them in comes first, which is also how a tie between games is broken where one must be picked.
     *
     * @return Collection<int, Game>
     */
    public function all(): Collection;

    public function find(int $id): ?Game;

    public function findBySlug(string $slug): ?Game;

    public function create(string $slug, string $name, string $idLabel, TelemetryInterface $interface): Game;

    /** Rename the game, relabel its player id, or change how its attendance is read. The slug never changes. */
    public function update(Game $game, string $name, string $idLabel, TelemetryInterface $interface): Game;

    /** Retire the game (`true`) or bring it back (`false`). Already in that state is left as it is. */
    public function setRetired(Game $game, bool $retired): Game;

    /**
     * The ids of the games something uses: a linked identity, a captured player or a dismissal.
     *
     * @return list<int>
     */
    public function inUseIds(): array;

    /**
     * Delete a game nothing uses. Checked under a lock on the game's row, in the caller's transaction, and the
     * foreign keys refuse it anyway, so a link landing in between cannot be orphaned.
     *
     * @throws GameInUseException when anything uses the game; nothing is deleted.
     */
    public function delete(Game $game): Game;
}
