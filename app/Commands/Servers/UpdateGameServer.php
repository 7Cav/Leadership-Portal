<?php

namespace App\Commands\Servers;

use App\Models\Game;
use App\Models\GameServer;
use App\Models\GameServerType;
use App\Models\TelemetryInterface;
use App\Models\User;
use App\Repositories\GameServerRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Select or deselect a fetched game server, assign it a game, mark it training or public, and give a CRCON server its
 * BattleMetrics twin as a fallback (#389). Admin-only, gated by the controller (AD-6). A selected server needs a game;
 * a retired game takes no newly assigned server, as it takes no new links (#388), but one already on it stays.
 * Saving what is already stored records nothing; any change is one audited action (AD-16).
 */
class UpdateGameServer
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidGameServerException when the choices do not fit together; nothing is written.
     */
    public function handle(User $actor, GameServer $server, bool $selected, ?Game $game, GameServerType $type, ?GameServer $fallback): GameServer
    {
        if ($selected && $game === null) {
            throw InvalidGameServerException::selectedWithoutGame();
        }

        if ($game !== null && $game->isRetired() && $game->id !== $server->game_id) {
            throw InvalidGameServerException::retiredGame($game->name);
        }

        if ($fallback !== null && $server->interface !== TelemetryInterface::Crcon) {
            throw InvalidGameServerException::fallbackNotAllowed();
        }

        if ($fallback !== null && ($fallback->interface !== TelemetryInterface::BattleMetrics || ! $fallback->selected)) {
            throw InvalidGameServerException::fallbackNotBattleMetrics();
        }

        if ($server->selected === $selected && $server->game_id === $game?->id && $server->type === $type
            && $server->fallback_server_id === $fallback?->id) {
            return $server;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'game_server.updated',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->servers->update($server, $selected, $game?->id, $type, $fallback?->id),
        );
    }
}
