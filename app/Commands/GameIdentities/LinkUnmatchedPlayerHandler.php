<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\AutoMarkResult;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Models\Game;
use App\Models\GameIdentityText;
use App\Models\IdKindFormat;
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
 *
 * The ID is the one the server reported, so it skips its kind's shape (#450) and is stored exactly as reported: the
 * capture matches on it. Server IDs are printable ASCII, which cleaning never changes; one that cleaning would change is
 * refused rather than stored altered. The reported name is cleaned like any other, and a name with nothing left takes
 * the ID, as the capture does for a player with no name. The ID is stored as the first of the game's kinds whose shape it
 * has exactly (an Epic ID on Hell Let Loose is stored as an Epic ID, not the game's default Steam ID); one that fits no
 * kind's shape (an Xbox ID) takes the kind the writer would give it anyway: the one the trooper's identity already has
 * for that ID, or the game's default.
 *
 * Two IDs are never swapped across kinds here: a trooper whose ID has a known kind's shape (a Steam or Epic ID) is not
 * offered a replace by an ID of another kind or of none, and is told to change it on the Game identities page if they
 * really changed platform. A player whose ID is a BattleMetrics player number read through CRCON's fallback
 * (`id_source`) is not linked at all: it is not the ID the game server reports, so linking it would leave the trooper
 * unmatched by every later capture, and replacing with it would lose the ID that works.
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
     * @throws InvalidGameIdentityException when cleaning would change the reported ID.
     */
    public function handle(User $actor, LinkUnmatchedPlayer $command): AutoMarkResult
    {
        [$practice, $player] = $this->listedPlayer($actor, $this->scope, $this->practices, $this->captured, $command);

        // Before the replace question: a leader must not be asked to replace an ID for a link that cannot be made.
        if ($command->game->isRetired()) {
            throw GameRetiredException::for($command->game);
        }

        if ($player->id_source !== null) {
            throw UnmatchedPlayerResolutionException::notAGameId();
        }

        if (WeekOf::isBeforeFloor($practice->week_start)) {
            throw UnmatchedPlayerResolutionException::weekBeforeFloor($practice->week_start);
        }

        $trooper = $this->authorizedTrooper($actor, $command->trooperId, $this->scope, $this->troopers);

        // On the practice's rollcall for its week: placed in the section now, or for a week that has ended, on the
        // roster it ended with, so the pre-mark below can count (the 2026-10-29 audit).
        if ($this->troopers->idsOnRollcall($practice->section_id, $practice->week_start, [$trooper->id])->isEmpty()) {
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

        // A held ID of a known kind is only ever replaced by one of the same kind, and that is refused before the
        // leader is asked: an Epic ID swapped for a Steam ID, or for an ID of no known shape, would leave the capture
        // matching on an ID it may never see again. An ID of no known shape (an Xbox ID, or a number linked earlier)
        // may still be replaced by any.
        $heldKind = $held === null ? null : self::kindOf($command->game, $held->platform_id);

        if ($heldKind !== null && $held->platform_id !== $player->platform_id && self::kindOf($command->game, $player->platform_id) !== $heldKind) {
            throw UnmatchedPlayerResolutionException::otherIdKind($heldKind, $command->game->name);
        }

        if ($held !== null && $held->platform_id !== $player->platform_id && $held->platform_id !== $replaceable) {
            throw UnmatchedPlayerResolutionException::alreadyHasIdentity($held->player_name, $held->platform_id, $command->game->name);
        }

        if (! GameIdentityText::isClean($player->platform_id)) {
            throw InvalidGameIdentityException::reportedIdNotClean();
        }

        $name = GameIdentityText::clean($player->player_name);

        // The check above answers the common case without a write; the writer repeats it under
        // lock inside its audited transaction, so a change landing between the two is refused too.
        $this->setIdentity->handle($actor, new SetTrooperGameIdentity(
            $trooper->id, $command->game, $name === '' ? $player->platform_id : $name, $player->platform_id, $replaceable,
            idKind: self::kindOf($command->game, $player->platform_id), reportedByServer: true,
        ));

        return $this->attendance->preMark($practice, [$trooper->id], $actor->id);
    }

    /**
     * The first of the game's ID kinds whose shape ({@see IdKindFormat}) `$platformId` has as reported, not altered to
     * fit, or null when none does: the writer then keeps the trooper's kind for that ID, or the game's default.
     */
    private static function kindOf(Game $game, string $platformId): ?string
    {
        foreach ($game->id_kinds as $kind) {
            if (IdKindFormat::for($kind)?->accepts($platformId) === true) {
                return $kind;
            }
        }

        return null;
    }
}
