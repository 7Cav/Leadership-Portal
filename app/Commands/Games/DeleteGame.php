<?php

namespace App\Commands\Games;

use App\Models\Game;
use App\Models\User;
use App\Repositories\GameInUseException;
use App\Repositories\GameRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Delete a game nothing uses — one added by mistake (#388). A game an identity, a captured player or a dismissal
 * uses is refused and must be retired instead; the check runs inside the audited transaction, under a lock, so a
 * refused delete leaves no log entry. Admin-only, gated by the controller (AD-6). Audited (AD-16).
 */
class DeleteGame
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws GameInUseException when anything uses the game; nothing is deleted or logged.
     */
    public function handle(User $actor, Game $game): Game
    {
        return $this->audit->record(
            actorId: $actor->id,
            action: 'game.deleted',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->games->delete($game),
        );
    }
}
