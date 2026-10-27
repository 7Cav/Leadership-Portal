<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\User;
use App\Repositories\GamePlayerDismissalRepository;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * A leader undoes a dismissal (#176, extends Story 7.7, AD-19): one audited action (AD-16),
 * `game_player.restored`, that drops the `(platform, platform_id)` dismissal, so the player is listed
 * again on this practice and on every later one that sees them. Scope-gated like the dismissal
 * (AD-8), through the same lookup: the section must be in the actor's write scope and the player one
 * this practice's capture stored, so a leader cannot clear a dismissal for a player their section
 * never saw. The capture run stores dismissed players too (`CaptureSectionPracticeHandler`), which is why dropping the dismissal is all it takes to bring the player back here. A player who is
 * not dismissed is refused with a message, never recorded as a restore.
 */
class RestoreDismissedPlayerHandler
{
    use ResolvesUnmatchedPlayers;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly SectionPracticeRepository $practices,
        private readonly PracticeCapturePlayerRepository $captured,
        private readonly GamePlayerDismissalRepository $dismissals,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws GameIdentityForbiddenException
     * @throws UnmatchedPlayerResolutionException
     */
    public function handle(User $actor, RestoreDismissedPlayer $command): void
    {
        [, $player] = $this->listedPlayer($actor, $this->scope, $this->practices, $this->captured, $command);

        $dismissal = $this->dismissals->find($command->game, $player->platform_id)
            ?? throw UnmatchedPlayerResolutionException::notDismissed();

        // The log entry's target is the dismissal that was dropped, as a removed identity's is.
        $this->audit->record(
            actorId: $actor->id,
            action: 'game_player.restored',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($command, $player, $dismissal) {
                $this->dismissals->forget($command->game, $player->platform_id);

                return $dismissal;
            },
        );
    }
}
