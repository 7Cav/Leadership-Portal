<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\User;
use App\Repositories\GameRepository;
use App\Repositories\UnitGame;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Set or clear the game a unit plays, picked from the Games list. Every unit under it plays the
 * same game unless it has one of its own ({@see UnitGame}). Game identities opens on it, and the
 * capture reads a section's practice from it.
 *
 * Who may is enforced by the controller (AD-6) through `ScopeResolver::maySetUnitGame()`: an Admin or Regimental HQ staff
 * on any unit, an overseer on the units they oversee and those under them, and DEVCOM's 1IC and 2IC on DEVCOM and the
 * units under it. Any unit may pick a game while no unit above it has one ({@see Unit::setsOwnGame()}); under one that
 * does, a game already set stays until it is cleared, and clearing it is the only change allowed there. A unit with
 * units under it that have games of their own is refused, naming them, until those are cleared. Audited through the
 * envelope (AD-16) as an org-structure edit, so no reason is required. Null clears it, so the unit inherits again; an
 * unchanged game writes nothing and logs nothing, so a unit keeps a game that has since been retired until it is
 * changed. A game that is gone, or retired, cannot be newly set.
 */
class SetUnitGame
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidUnitGameException when the game does not exist or is retired, or a unit above has a game, or units under it have their own.
     */
    public function handle(User $actor, Unit $unit, ?int $gameId): Unit
    {
        if ($gameId === $unit->game_id) {
            return $unit;
        }

        if ($gameId !== null) {
            $this->refuseLocked($unit);
        }

        if ($gameId !== null && $this->games->find($gameId) === null) {
            throw InvalidUnitGameException::noSuchGame();
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.game_set',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($unit, $gameId) {
                if ($gameId !== null) {
                    // Read again under a shared lock held to the end of the transaction, so the game cannot be retired
                    // between this check and the write. One deleted in between is a 404.
                    $game = $this->games->lock($gameId, exclusive: false);

                    if ($game->isRetired()) {
                        throw InvalidUnitGameException::retired($game);
                    }
                }

                return $this->units->setGame($unit, $gameId);
            },
        );
    }

    /**
     * Refuse a new game on `$unit` when a unit above it has one (it plays that game), or when units under it have games
     * of their own (they would silently stop following it). One query over the tree.
     */
    private function refuseLocked(Unit $unit): void
    {
        $tree = $this->units->tree()->keyBy('id');

        $ancestors = [];
        for ($at = $tree->get($unit->parent_id), $depth = 0; $at !== null && $depth < 10; $depth++, $at = $tree->get($at->parent_id)) {
            $ancestors[] = $at;
        }
        if (($lock = Unit::gameLockOf($ancestors)) !== null) {
            throw InvalidUnitGameException::lockedBy($lock);
        }

        $below = $tree->filter(fn (Unit $other) => $other->id !== $unit->id
            && $other->game_id !== null
            && str_starts_with((string) $other->path, (string) $unit->path))->values();
        if ($below->isNotEmpty()) {
            throw InvalidUnitGameException::setBelow($below);
        }
    }
}
