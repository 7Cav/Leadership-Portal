<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\IdKindFormat;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\GamePlayerDismissalRepository;
use App\Repositories\GameRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * The one writer of record for `trooper_game_identities` (AD-22): a leader links a trooper to an
 * in-game identity by hand (AD-19), and every link or correction is one audited action (AD-16).
 *
 * Refuses a trooper outside the actor's scope (AD-8) before anything else. A trooper holds one
 * identity per game, so setting it again replaces theirs — a rename (same platform id, new
 * name) updates the row, never adds one. Saving what is already stored is a no-op that records
 * nothing, so a double-click leaves one audit entry. An identifier already linked to another
 * trooper is refused by the repository and rolls the whole action back, log entry included.
 * Linking never touches `attendance`, and clears any dismissal of that identifier (7.7). A retired
 * game is refused outright (#388), a correction of an identity it already has included: retiring
 * a game freezes its links, and removing one stays possible.
 *
 * The identity records which of the game's ID kinds it was entered as (#428): the one the command names, else the
 * kind the trooper's identity there already has for the same id, else the game's default; a kind the game does not have is refused.
 * Changing only the kind is an update and audited; the one-owner rule on a platform id ignores the kind, so the
 * same id is never linked twice on a game. The kind is part of the identity row, so it is in the audit entry's digest.
 *
 * A name or ID that is empty once cleaned is refused, and so is an ID without its kind's shape ({@see IdKindFormat},
 * #450), unless a game server reported it; an Epic ID is stored lower case. Both are checked before anything is written.
 *
 * A command carrying `replacing` is a conditional write (#176, see {@see SetTrooperGameIdentity}):
 * if the trooper's identity in that game has changed to something else since the leader was
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
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws GameIdentityForbiddenException when the trooper is outside the actor's scope.
     * @throws GameRetiredException when the game is retired.
     * @throws UnknownIdKindException when the command names a kind the game does not have.
     * @throws InvalidGameIdentityException when the name or ID is empty once cleaned, or the ID lacks its kind's shape.
     * @throws GameIdentityLinkException when the platform id already belongs to another trooper.
     * @throws UnmatchedPlayerResolutionException `has_other_identity`, when `replacing` is set and
     *                                            the trooper's identity changed to another since.
     */
    public function handle(User $actor, SetTrooperGameIdentity $command): TrooperGameIdentity
    {
        $this->authorizedTrooper($actor, $command->trooperId, $this->scope, $this->troopers);

        if ($command->game->isRetired()) {
            throw GameRetiredException::for($command->game);
        }

        if ($command->playerName === '') {
            throw InvalidGameIdentityException::emptyName();
        }

        if ($command->platformId === '') {
            throw InvalidGameIdentityException::emptyId();
        }

        $existing = $this->identities->forTrooper($command->trooperId)->firstWhere('game_id', $command->game->id);

        // A different id is a different player id, so it does not inherit the old one's kind.
        $inherited = $existing?->platform_id === $command->platformId ? $existing->id_kind : null;
        $idKind = $command->idKind ?? $inherited ?? $command->game->defaultIdKind();

        if (! $command->game->hasIdKind($idKind)) {
            throw UnknownIdKindException::for($command->game, $idKind);
        }

        $platformId = $command->platformId;
        $format = $command->reportedByServer ? null : IdKindFormat::for($idKind);

        if ($format !== null) {
            $platformId = $format->canonical($platformId);

            if (! $format->accepts($platformId)) {
                throw InvalidGameIdentityException::badFormat($format);
            }
        }

        if ($existing?->player_name === $command->playerName && $existing->platform_id === $platformId && $existing->id_kind === $idKind) {
            return $existing;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: $existing === null ? 'game_identity.linked' : 'game_identity.updated',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($command, $idKind, $platformId) {
                // The kind is checked again against the game as stored, with its row locked shared, so a kind an
                // Admin removed since the check above (UpdateGame takes the lock exclusive) is refused, not stored.
                $game = $this->games->lock($command->game->id, exclusive: false);

                if (! $game->hasIdKind($idKind)) {
                    throw UnknownIdKindException::for($game, $idKind);
                }

                // A conditional write (#176): the leader agreed to replace the identity they were
                // shown, not whatever the trooper holds by the time the request lands. Read under
                // lock first, so another leader's change (or the Game identities screen) either
                // committed before this check and is refused, or waits for this transaction. The
                // refusal carries what the trooper holds now, so the screen can ask again.
                if ($command->replacing !== null) {
                    $held = $this->identities->lockForTrooper($command->trooperId, $command->game);

                    if ($held !== null && $held->platform_id !== $platformId && $held->platform_id !== $command->replacing) {
                        throw UnmatchedPlayerResolutionException::alreadyHasIdentity($held->player_name, $held->platform_id, $command->game->name);
                    }
                }

                $identity = $this->identities->link($command->trooperId, $command->game, $command->playerName, $platformId, $idKind);
                // Linking a player a leader once dismissed re-admits them (7.7), in the same transaction.
                $this->dismissals->forget($command->game, $platformId);

                return $identity;
            },
        );
    }
}
