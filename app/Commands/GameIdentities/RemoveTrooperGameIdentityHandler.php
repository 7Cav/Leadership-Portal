<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\User;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Removes a trooper's game identity as one audited action (AD-16, AD-22); the log entry's target
 * is the removed row. Scope-gated like the link (AD-8). Removing a link that is not there is a
 * no-op that records nothing. A link is metadata for future resolution, so `attendance` rows an
 * earlier capture already stamped are untouched.
 */
class RemoveTrooperGameIdentityHandler
{
    use AuthorizesGameIdentityChange;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @return bool true when a link was removed.
     *
     * @throws GameIdentityForbiddenException when the trooper is outside the actor's scope.
     */
    public function handle(User $actor, RemoveTrooperGameIdentity $command): bool
    {
        $this->authorizedTrooper($actor, $command->trooperId, $this->scope, $this->troopers);

        $existing = $this->identities->forTrooper($command->trooperId)->firstWhere('platform', $command->platform);

        if ($existing === null) {
            return false;
        }

        $this->audit->record(
            actorId: $actor->id,
            action: 'game_identity.removed',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($command, $existing) {
                $this->identities->unlink($command->trooperId, $command->platform);

                return $existing;
            },
        );

        return true;
    }
}
