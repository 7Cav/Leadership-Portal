<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\Game;
use App\Models\Gamertag;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use App\Repositories\GameRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * "Fill in from game server" on a saved identity: the game's servers say which platform the ID is and the player's
 * current name ({@see PlatformLookup}), and the identity takes that kind (when the game asks for it) and that name as
 * its gamertag, marked as from the game server. The ID itself never changes. A change is one audited
 * `game_identity.updated`; when the servers say nothing new, nothing is written or logged. Gated as an identity write is
 * (AD-8), and a retired game's identities are frozen.
 */
class FillIdentityFromServer
{
    use AuthorizesGameIdentityChange;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
        private readonly PlatformLookup $lookup,
    ) {}

    /**
     * @return array{0: PlatformLookupResult, 1: bool} what the servers said, and whether the identity changed
     *
     * @throws GameIdentityForbiddenException when the trooper is outside the actor's write scope.
     * @throws GameRetiredException when the game is retired.
     * @throws InvalidGameIdentityException when the trooper has no identity on the game.
     */
    public function handle(User $actor, int $trooperId, Game $game): array
    {
        $this->authorizedTrooper($actor, $trooperId, $this->scope, $this->troopers);

        if ($game->isRetired()) {
            throw GameRetiredException::for($game);
        }

        $existing = $this->identities->forTrooper($trooperId)->firstWhere('game_id', $game->id);

        if (! $existing instanceof TrooperGameIdentity) {
            throw InvalidGameIdentityException::emptyId();
        }

        $result = $this->lookup->lookup($game, $existing->platform_id);

        if (! $result->isFound()) {
            return [$result, false];
        }

        $kind = $result->kind ?? $existing->id_kind;
        $gamertag = $result->name === null ? null : new Gamertag($result->name, fromServer: true);
        $changed = $kind !== $existing->id_kind
            || ($gamertag !== null && ($gamertag->value !== $existing->gamertag || ! $existing->gamertag_from_server));

        if (! $changed) {
            return [$result, false];
        }

        $this->audit->record(
            actorId: $actor->id,
            action: 'game_identity.updated',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($existing, $game, $kind, $gamertag) {
                $locked = $this->games->lock($game->id, exclusive: false);

                if (! $locked->hasIdKind($kind)) {
                    throw UnknownIdKindException::for($locked, $kind);
                }

                return $this->identities->link($existing->trooper_id, $game, $existing->platform_id, $kind, $existing->source, $gamertag);
            },
        );

        return [$result, true];
    }
}
