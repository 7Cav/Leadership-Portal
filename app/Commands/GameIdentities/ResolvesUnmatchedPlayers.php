<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\User;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Support\Time\WeekOf;

/**
 * The gate and lookup shared by the link, dismiss and restore handlers (Story 7.7, #176): the practice's section
 * must be in the actor's write scope (AD-8), and the player must be one that practice's
 * capture stored as unmatched.
 */
trait ResolvesUnmatchedPlayers
{
    /**
     * @return array{SectionPractice, PracticeCapturePlayer}
     *
     * @throws GameIdentityForbiddenException when the section is outside the actor's scope.
     * @throws UnmatchedPlayerResolutionException when the practice or the player is not there.
     */
    private function listedPlayer(
        User $actor,
        ScopeResolver $scope,
        SectionPracticeRepository $practices,
        PracticeCapturePlayerRepository $captured,
        LinkUnmatchedPlayer|DismissUnmatchedPlayer|RestoreDismissedPlayer $command,
    ): array {
        if (! $scope->writeScope($actor)->contains($command->sectionId)) {
            throw GameIdentityForbiddenException::forSection($command->sectionId);
        }

        $practice = $practices->find($command->sectionId, WeekOf::start($command->week));
        $player = $practice === null ? null : $captured->findUnmatched($practice->id, $command->game, trim($command->platformId));

        return [$practice ?? throw UnmatchedPlayerResolutionException::notListed(), $player ?? throw UnmatchedPlayerResolutionException::notListed()];
    }
}
