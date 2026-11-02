<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\Game;
use App\Models\User;
use App\Repositories\TrooperRepository;

/**
 * The add form's "Fill in from game server": the platform and gamertag of an ID a leader typed, before it is saved.
 * Writes nothing. Gated as an identity write is (AD-8): only a trooper the actor may change can be looked up for, so
 * the lookup cannot be used to ask the game servers about anyone at all.
 */
class LookUpPlayerPlatform
{
    use AuthorizesGameIdentityChange;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly PlatformLookup $lookup,
    ) {}

    /**
     * @throws GameIdentityForbiddenException when the trooper is outside the actor's write scope.
     */
    public function handle(User $actor, int $trooperId, Game $game, string $playerId): PlatformLookupResult
    {
        $this->authorizedTrooper($actor, $trooperId, $this->scope, $this->troopers);

        return $this->lookup->lookup($game, $playerId);
    }
}
