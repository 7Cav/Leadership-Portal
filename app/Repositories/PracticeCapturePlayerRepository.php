<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\PracticeCapturePlayer;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `practice_capture_players` (AD-2): what a capture run saw but did not credit,
 * kept for the leader's review (Story 7.7). The capture handler is its one writer.
 */
interface PracticeCapturePlayerRepository
{
    /**
     * Replace everything stored for `$practiceId` with this run's findings, atomically: a re-run
     * never leaves the earlier run's players behind or duplicates them. A player listed twice is
     * stored once, and so is an ID listed in two letter cases (the first is kept).
     *
     * @param  list<array{platform_id: string, player_name: string, trooper_id: ?int}>  $players
     */
    public function replaceForPractice(int $practiceId, Game $game, array $players): void;

    /**
     * The practice's players still needing a trooper: unmatched, not dismissed, and not linked
     * since the run. By name.
     *
     * @return Collection<int, PracticeCapturePlayer>
     */
    public function unmatchedFor(int $practiceId): Collection;

    /**
     * The practice's unmatched players a leader has dismissed and nobody has linked since: the
     * drawer's "Dismissed" list, where a dismissal can be undone (#176). Only players this
     * practice's capture saw, since the capture stores dismissed players too. By name.
     *
     * @return Collection<int, PracticeCapturePlayer>
     */
    public function dismissedFor(int $practiceId): Collection;

    /**
     * One stored unmatched player of the practice, however it has been resolved since.
     */
    public function findUnmatched(int $practiceId, Game $game, string $platformId): ?PracticeCapturePlayer;

    /**
     * The practices whose latest capture saw `$trooperId` without crediting them (#448): what a staff member's milpac
     * lists as seen by the game server. One row per practice (a trooper linked through two identities is seen once),
     * with the practice's section loaded, newest week first.
     *
     * @return Collection<int, PracticeCapturePlayer>
     */
    public function seenTrooper(int $trooperId): Collection;
}
