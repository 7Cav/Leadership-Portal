<?php

namespace App\Commands\Games;

use App\Models\Game;
use App\Models\TelemetryInterface;
use App\Models\User;
use App\Repositories\GameRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Rename a game, relabel what its player id is called, or change which built-in telemetry interface reads its
 * attendance (#388). Admin-only, gated by the controller (AD-6). The slug stays. Saving what is already stored
 * records nothing; any change is one audited action (AD-16).
 */
class UpdateGame
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(User $actor, Game $game, string $name, string $idLabel, TelemetryInterface $interface): Game
    {
        $name = trim($name);
        $idLabel = trim($idLabel);

        if ($game->name === $name && $game->id_label === $idLabel && $game->interface === $interface) {
            return $game;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'game.updated',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->games->update($game, $name, $idLabel, $interface),
        );
    }
}
