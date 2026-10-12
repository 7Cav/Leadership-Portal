<?php

namespace App\Repositories;

use App\Models\GamePlatform;
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
     * stored once.
     *
     * @param  list<array{platform_id: string, player_name: string, trooper_id: ?int}>  $players
     */
    public function replaceForPractice(int $practiceId, GamePlatform $platform, array $players): void;

    /**
     * The practice's players still needing a trooper: unmatched, not dismissed, and not linked
     * since the run. By name.
     *
     * @return Collection<int, PracticeCapturePlayer>
     */
    public function unmatchedFor(int $practiceId): Collection;

    /**
     * One stored unmatched player of the practice, however it has been resolved since.
     */
    public function findUnmatched(int $practiceId, GamePlatform $platform, string $platformId): ?PracticeCapturePlayer;
}
