<?php

namespace App\Commands\Games;

use App\Models\Game;
use App\Models\TelemetryInterface;
use App\Models\User;
use App\Repositories\GameRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Rename a game, change the kinds of player ID it asks for, or change which built-in telemetry interface reads its
 * attendance (#388, #428). Admin-only, gated by the controller (AD-6). The slug stays. Saving what is already stored
 * records nothing; any change is one audited action (AD-16). A kind a linked identity uses cannot be removed (so
 * renaming one is refused too): the check runs inside the audited transaction, so a refusal leaves no log entry.
 */
class UpdateGame
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @param  list<string>  $idKinds
     *
     * @throws InvalidGameException when a kind the game loses is one a linked identity uses.
     */
    public function handle(User $actor, Game $game, string $name, array $idKinds, TelemetryInterface $interface): Game
    {
        $name = trim($name);
        $idKinds = array_values(array_map('trim', $idKinds));

        if ($game->name === $name && $game->id_kinds === $idKinds && $game->interface === $interface) {
            return $game;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'game.updated',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($game, $name, $idKinds, $interface) {
                $removed = array_intersect($this->identities->kindsInUse($game), array_diff($game->id_kinds, $idKinds));

                if ($removed !== []) {
                    throw InvalidGameException::idKindInUse(array_values($removed));
                }

                return $this->games->update($game, $name, $idKinds, $interface);
            },
        );
    }
}
