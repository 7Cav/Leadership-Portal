<?php

namespace App\Commands\Games;

use App\Models\Game;
use App\Models\User;
use App\Repositories\GameRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Retire a game (`$retired` true) or bring it back (#388). A retired game cannot be newly linked to a trooper; every
 * identity, capture and dismissal that already uses it stays, so retiring is how a game in use is taken out of play
 * (it cannot be deleted). Admin-only, gated by the controller (AD-6). Already in that state records nothing;
 * otherwise one audited action (AD-16).
 */
class RetireGame
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(User $actor, Game $game, bool $retired): Game
    {
        if ($game->isRetired() === $retired) {
            return $game;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: $retired ? 'game.retired' : 'game.reinstated',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->games->setRetired($game, $retired),
        );
    }
}
