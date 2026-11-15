<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Models\IdentitySource;
use App\Models\User;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Support\Time\WeekOf;

/**
 * Undoes a link made from the unmatched list ({@see LinkUnmatchedPlayerHandler}), through the same writers: the
 * identity through {@see SetTrooperGameIdentityHandler} (the ID it replaced put back) or
 * {@see RemoveTrooperGameIdentityHandler} (none before), then the attended mark through
 * {@see MarkAttendanceHandler::undoPreMark()}, which leaves a mark anyone changed since. Each is its own audited action.
 *
 * Scope-gated like the link (AD-8): the practice's section and the trooper in the actor's write scope, the player one
 * that practice's capture stored. Refused when the trooper no longer holds the player's ID on that game (changed or
 * removed since the link), so an Undo never takes away an identity someone set afterwards, and for a week before
 * `WeekOf::floor()`.
 */
class UndoUnmatchedLinkHandler
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
        private readonly RemoveTrooperGameIdentityHandler $removeIdentity,
        private readonly MarkAttendanceHandler $attendance,
    ) {}

    /**
     * Returns whether the attended mark was taken back.
     *
     * @throws GameIdentityForbiddenException
     * @throws UnmatchedPlayerResolutionException
     */
    public function handle(User $actor, UndoUnmatchedLink $command): bool
    {
        [$practice, $player] = $this->listedPlayer($actor, $this->scope, $this->practices, $this->captured, $command);

        if (WeekOf::isBeforeFloor($practice->week_start)) {
            throw UnmatchedPlayerResolutionException::weekBeforeFloor($practice->week_start);
        }

        $trooper = $this->authorizedTrooper($actor, $command->trooperId, $this->scope, $this->troopers);
        $held = $this->identities->forTrooper($trooper->id)->firstWhere('game_id', $command->game->id);

        if ($held === null || $held->platform_id !== $player->platform_id) {
            throw UnmatchedPlayerResolutionException::linkChanged();
        }

        if ($command->replaced !== null && $command->replaced !== '' && $command->replaced !== $player->platform_id) {
            $this->setIdentity->handle($actor, new SetTrooperGameIdentity(
                $trooper->id, $command->game, $command->replaced, $player->platform_id, source: IdentitySource::FromRollcall,
            ));
        } else {
            $this->removeIdentity->handle($actor, new RemoveTrooperGameIdentity($trooper->id, $command->game));
        }

        return $command->clearMark && $this->attendance->undoPreMark($practice, $trooper->id, $command->restoreMissed, $actor->id);
    }
}
