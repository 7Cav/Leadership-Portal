<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\User;
use App\Repositories\GamePlayerDismissalRepository;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * A leader dismisses an unmatched player as not-a-member (Story 7.7, AD-19), one audited action
 * (AD-16) whose target is the dismissal. From then on that identifier is left out of this and every
 * later unmatched list on its game, until it is linked to a trooper. Scope-gated like the link
 * (AD-8). A player linked since the capture is refused: a dismissal must not shadow a real link.
 */
class DismissUnmatchedPlayerHandler
{
    use ResolvesUnmatchedPlayers;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly SectionPracticeRepository $practices,
        private readonly PracticeCapturePlayerRepository $captured,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly GamePlayerDismissalRepository $dismissals,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws GameIdentityForbiddenException
     * @throws UnmatchedPlayerResolutionException
     */
    public function handle(User $actor, DismissUnmatchedPlayer $command): void
    {
        [, $player] = $this->listedPlayer($actor, $this->scope, $this->practices, $this->captured, $command);

        if ($this->identities->findByPlatformId($command->game, $player->platform_id) !== null) {
            throw UnmatchedPlayerResolutionException::notListed();
        }

        $this->audit->record(
            actorId: $actor->id,
            action: 'game_player.dismissed',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->dismissals->dismiss($command->game, $player->platform_id, $player->player_name, $actor->id),
        );
    }
}
