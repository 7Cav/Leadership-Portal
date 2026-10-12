<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\AutoMarkResult;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Models\User;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;

/**
 * Resolves an unmatched player to the trooper a leader chose (Story 7.7, AD-19 — the system only
 * ever suggests): the identity is written by 7.2's {@see SetTrooperGameIdentityHandler} (one
 * writer, audited), then the trooper is pre-marked through {@see MarkAttendanceHandler::preMark()}
 * (one attendance writer; a manual or LOA mark is kept, AD-14/AD-22). Each is its own audited
 * action. Both steps are idempotent, so a request that failed between them can simply be repeated.
 *
 * Refuses a section or trooper outside the actor's scope (AD-8); a trooper from another section
 * (the mark would not count there); and a trooper who already holds a different identity on that
 * game, which linking would silently replace. An identifier another trooper owns surfaces as
 * {@see GameIdentityLinkException}, never reassigned.
 */
class LinkUnmatchedPlayerHandler
{
    use AuthorizesGameIdentityChange;
    use ResolvesUnmatchedPlayers;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly SectionPracticeRepository $practices,
        private readonly PracticeCapturePlayerRepository $captured,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly SetTrooperGameIdentityHandler $setIdentity,
        private readonly MarkAttendanceHandler $attendance,
    ) {}

    /**
     * @throws GameIdentityForbiddenException
     * @throws UnmatchedPlayerResolutionException
     * @throws GameIdentityLinkException when the platform id already belongs to another trooper.
     */
    public function handle(User $actor, LinkUnmatchedPlayer $command): AutoMarkResult
    {
        [$practice, $player] = $this->listedPlayer($actor, $this->scope, $this->practices, $this->captured, $command);
        $trooper = $this->authorizedTrooper($actor, $command->trooperId, $this->scope, $this->troopers);

        if ($trooper->primary_section_id !== $practice->section_id) {
            throw UnmatchedPlayerResolutionException::notInSection();
        }

        $held = $this->identities->forTrooper($trooper->id)->firstWhere('platform', $command->platform);

        if ($held !== null && $held->platform_id !== $player->platform_id) {
            throw UnmatchedPlayerResolutionException::alreadyHasIdentity($held->player_name, $command->platform->label());
        }

        $this->setIdentity->handle($actor, new SetTrooperGameIdentity($trooper->id, $command->platform, $player->player_name, $player->platform_id));

        return $this->attendance->preMark($practice, [$trooper->id], $actor->id);
    }
}
