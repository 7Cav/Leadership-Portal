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
use App\Support\Time\WeekOf;

/**
 * Resolves an unmatched player to the trooper a leader chose (Story 7.7, AD-19 — the system only
 * ever suggests): the identity is written by 7.2's {@see SetTrooperGameIdentityHandler} (one
 * writer, audited), then the trooper is pre-marked through {@see MarkAttendanceHandler::preMark()}
 * (one attendance writer; a manual or LOA mark is kept, AD-14/AD-22). Each is its own audited
 * action. Both steps are idempotent, so a request that failed between them can simply be repeated.
 *
 * Refuses a section or trooper outside the actor's scope (AD-8); a practice whose week is before
 * `WeekOf::floor()`, since the pre-mark would write to it; and a trooper from another section
 * (the mark would not count there). A trooper who already holds a different identity on that game
 * is refused with the `has_other_identity` reason and that identity, so the screen can ask the
 * leader to replace or cancel (#176); only a command carrying `replace` overwrites it, through the
 * same audited writer (`game_identity.updated`, AD-16), and the pre-mark rule is unchanged. The
 * replace names the identity it replaces (`replacing`, the platform id the leader was shown): if
 * the trooper's identity changed since, it is refused the same way with the new one, so the
 * leader is asked again rather than overwriting an identity they never saw. An
 * identifier another trooper owns surfaces as {@see GameIdentityLinkException}, never reassigned,
 * whether or not `replace` is set: replacing only ever changes the chosen trooper's own row.
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
     * @throws GameRetiredException when the player's game is retired.
     * @throws GameIdentityLinkException when the platform id already belongs to another trooper.
     */
    public function handle(User $actor, LinkUnmatchedPlayer $command): AutoMarkResult
    {
        [$practice, $player] = $this->listedPlayer($actor, $this->scope, $this->practices, $this->captured, $command);

        if (WeekOf::isBeforeFloor($practice->week_start)) {
            throw UnmatchedPlayerResolutionException::weekBeforeFloor($practice->week_start);
        }

        $trooper = $this->authorizedTrooper($actor, $command->trooperId, $this->scope, $this->troopers);

        if ($trooper->primary_section_id !== $practice->section_id) {
            throw UnmatchedPlayerResolutionException::notInSection();
        }

        // Checked before the replace question: a leader must not be asked to replace a trooper's
        // identity for a link that would then fail because someone else owns the player. The
        // repository's UNIQUE guard still decides a race between this read and the write.
        $owner = $this->identities->findByPlatformId($command->game, $player->platform_id);

        if ($owner !== null && $owner->trooper_id !== $trooper->id) {
            throw GameIdentityLinkException::alreadyLinked($command->game, $player->platform_id);
        }

        // The one identity this link may overwrite: the one the leader was shown and agreed to
        // replace, or — without a replace, or a replace that does not say what it replaces — none
        // at all (passing the player's own id lets only "no identity yet" or "already this one"
        // through).
        $replaceable = $command->replace && $command->replacing !== null ? $command->replacing : $player->platform_id;
        $held = $this->identities->forTrooper($trooper->id)->firstWhere('game_id', $command->game->id);

        if ($held !== null && $held->platform_id !== $player->platform_id && $held->platform_id !== $replaceable) {
            throw UnmatchedPlayerResolutionException::alreadyHasIdentity($held->player_name, $held->platform_id, $command->game->name);
        }

        // The check above answers the common case without a write; the writer repeats it under
        // lock inside its audited transaction, so a change landing between the two is refused too.
        $this->setIdentity->handle($actor, new SetTrooperGameIdentity($trooper->id, $command->game, $player->player_name, $player->platform_id, $replaceable));

        return $this->attendance->preMark($practice, [$trooper->id], $actor->id);
    }
}
