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
 * Admin-only, enforced by the controller (AD-6), same as {@see SetUnitLabel}. Audited through the envelope (AD-16) as
 * an org-structure edit, so no reason is required. Null clears it, so the unit inherits again; an unchanged game writes
 * nothing and logs nothing, so a unit keeps a game that has since been retired until an Admin changes it. A game that is
 * gone, or retired, cannot be newly set.
 */
class SetUnitGame
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidUnitGameException when the game does not exist or is retired.
     */
    public function handle(User $actor, Unit $unit, ?int $gameId): Unit
    {
        if ($gameId === $unit->game_id) {
            return $unit;
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
}
