<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\GamePlayerDismissalRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * The one writer of record for `trooper_game_identities` (AD-22): a leader links a trooper to an
 * in-game identity by hand (AD-19), and every link or correction is one audited action (AD-16).
 *
 * Refuses a trooper outside the actor's scope (AD-8) before anything else. A trooper holds one
 * identity per platform, so setting it again replaces theirs — a rename (same platform id, new
 * name) updates the row, never adds one. Saving what is already stored is a no-op that records
 * nothing, so a double-click leaves one audit entry. An identifier already linked to another
 * trooper is refused by the repository and rolls the whole action back, log entry included.
 * Linking never touches `attendance`, and clears any dismissal of that identifier (7.7).
 *
 * A command carrying `replacing` is a conditional write (#176, see {@see SetTrooperGameIdentity}):
 * if the trooper's identity on that platform has changed to something else since the leader was
 * shown it, the write is refused as `has_other_identity` with the identity they hold now, inside
 * the same transaction, so nothing is written and nothing is logged.
 */
class SetTrooperGameIdentityHandler
{
    use AuthorizesGameIdentityChange;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly GamePlayerDismissalRepository $dismissals,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws GameIdentityForbiddenException when the trooper is outside the actor's scope.
     * @throws GameIdentityLinkException when the platform id already belongs to another trooper.
     * @throws UnmatchedPlayerResolutionException `has_other_identity`, when `replacing` is set and
     *                                            the trooper's identity changed to another since.
     */
    public function handle(User $actor, SetTrooperGameIdentity $command): TrooperGameIdentity
    {
        $this->authorizedTrooper($actor, $command->trooperId, $this->scope, $this->troopers);

        $existing = $this->identities->forTrooper($command->trooperId)->firstWhere('platform', $command->platform);

        if ($existing?->player_name === $command->playerName && $existing->platform_id === $command->platformId) {
            return $existing;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: $existing === null ? 'game_identity.linked' : 'game_identity.updated',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($command) {
                // A conditional write (#176): the leader agreed to replace the identity they were
                // shown, not whatever the trooper holds by the time the request lands. Read under
                // lock first, so another leader's change (or the Game identities screen) either
                // committed before this check and is refused, or waits for this transaction. The
                // refusal carries what the trooper holds now, so the screen can ask again.
                if ($command->replacing !== null) {
                    $held = $this->identities->lockForTrooper($command->trooperId, $command->platform);

                    if ($held !== null && $held->platform_id !== $command->platformId && $held->platform_id !== $command->replacing) {
                        throw UnmatchedPlayerResolutionException::alreadyHasIdentity($held->player_name, $held->platform_id, $command->platform->label());
                    }
                }

                $identity = $this->identities->link($command->trooperId, $command->platform, $command->playerName, $command->platformId);
                // Linking a player a leader once dismissed re-admits them (7.7), in the same transaction.
                $this->dismissals->forget($command->platform, $command->platformId);

                return $identity;
            },
        );
    }
}
