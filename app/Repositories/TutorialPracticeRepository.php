<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The tutorial's practice data (`App\Tutorial`). Every method writes or reads the default connection, which is the
 * person's own practice copy while the tutorial sandbox is entered; the world builder only calls it from inside the
 * sandbox. Never used on the portal's real data.
 */
interface TutorialPracticeRepository
{
    /** The practice copy's account for the signed-in person, under the same id as their real one. */
    public function copyUser(int $id, int $forumUserId, ?int $cavId, string $username, bool $isAdmin): User;

    /** A made-up account for a practice leader, so sent practice reports name someone. */
    public function practiceUser(int $forumUserId, int $cavId, string $username): User;

    public function user(int $id): ?User;

    public function unit(?Unit $parent, UnitType $type, string $name, string $callsign): Unit;

    public function game(string $name, string $slug, string $abbreviation): Game;

    public function setUnitGame(Unit $unit, Game $game): void;

    /** A practice trooper placed on `$unit` since `$since`, holding the command billet of `$commands` when given. */
    public function trooper(int $cavId, ?int $forumUserId, string $rankShort, string $rank, string $username, string $billet, Unit $unit, CarbonInterface $since, ?Unit $commands = null, TrooperStatus $status = TrooperStatus::Combat): Trooper;

    public function loa(Trooper $trooper, CarbonInterface $from, CarbonInterface $until): void;

    public function slot(Unit $section, int $weekday, int $startMinute, CarbonInterface $since): void;

    public function captured(SectionPractice $practice, CarbonInterface $at, CarbonInterface $windowStart, CarbonInterface $windowEnd): SectionPractice;

    /** Links every trooper in the practice copy to `$game`, so no tutorial page warns about a missing identity. */
    public function linkEveryone(Game $game): void;

    public function capturePlayer(SectionPractice $practice, Game $game, string $playerName, string $platformId, ?Trooper $trooper): void;

    /** The practice unit by its callsign. */
    public function unitByCallsign(string $callsign): ?Unit;

    public function trooperByUsername(string $username): ?Trooper;

    /**
     * What the person has done in their practice copy since it was made, for the coach card's ticks.
     *
     * @return array{visits: list<string>, demoSigned: bool, demoMarked: bool, confirmed: bool, posted: bool, draft: bool, sent: bool, staffSent: bool}
     */
    public function progress(int $userId, ?int $sectionId, ?int $reportUnitId, ?int $demoTrooperId, ?int $personaTrooperId, CarbonInterface $week): array;
}
