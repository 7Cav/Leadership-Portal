<?php

namespace App\Commands\StaffReports;

use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\CaptureSourceUnavailable;
use App\Models\StaffReport;
use App\Models\Unit;
use App\Repositories\GameRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\UnitGame;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;

/**
 * What the game servers marked public say about a staff member's claimed public server hour: their minutes over the
 * whole Cav week (up to now, while it runs) on every selected public server of the game in effect for their HQ
 * ({@see UnitGame}), read through each server's sources in order ({@see CaptureSources::publicFor()}) and summed.
 *
 * `confirmed` at {@see MINUTES} or more, `conflict` under it, also when some server could not be read: the minutes
 * found are kept with it, so a reader sees how much the servers did show. `no_data`, with no minutes, only when there
 * is nothing to compare at all, with a `reason`: `no_server` (no game, or no public server set up for it), `no_identity`
 * (no linked game identity for the game) or `unreadable` (servers set up, none could be read). The reason is null
 * with any other check.
 * A server that only knows who was seen (no minutes) counts as not readable. Never blocks the send.
 */
class PublicServerHour
{
    public const MINUTES = 60;

    public function __construct(
        private readonly CaptureSources $sources,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly GameRepository $games,
        private readonly UnitRepository $units,
    ) {}

    /**
     * @return array{check: string, minutes: ?int, reason: ?string}
     */
    public function check(int $trooperId, Unit $hq, CarbonImmutable $weekStart): array
    {
        $noData = fn (string $reason) => ['check' => StaffReport::PRESENCE_NO_DATA, 'minutes' => null, 'reason' => $reason];
        $gameId = (UnitGame::resolve($this->units->tree())[$hq->id] ?? null)?->gameId;
        $game = $gameId === null ? null : $this->games->find($gameId);
        $identity = $game === null ? null : $this->identities->forTrooper($trooperId)->firstWhere('game_id', $game->id);
        $servers = $game === null ? [] : $this->sources->publicFor($game);

        if ($servers === []) {
            return $noData(StaffReport::NO_DATA_NO_SERVER);
        }
        if ($identity === null) {
            return $noData(StaffReport::NO_DATA_NO_IDENTITY);
        }

        $platformId = mb_strtolower((string) $identity->platform_id);
        $start = $weekStart->utc()->toDateTimeImmutable();
        $end = min($weekStart->utc()->addWeek()->subSecond(), CarbonImmutable::now('UTC'))->toDateTimeImmutable();
        $minutes = 0;
        $read = 0;

        foreach ($servers as $server) {
            $presence = null;
            foreach ($server->configuredSources() as $source) {
                try {
                    $presence = ($source->fetch)($start, $end);
                    break;
                } catch (CaptureSourceUnavailable) {
                    continue;
                }
            }

            if ($presence === null) {
                continue;
            }

            $here = 0;
            $timed = true;
            foreach ($presence->players as $player) {
                if (mb_strtolower($player->platformId) !== $platformId) {
                    continue;
                }
                if ($player->minutes === null) {
                    $timed = false;
                } else {
                    $here += $player->minutes;
                }
            }

            if ($timed) {
                $read++;
                $minutes += $here;
            }
        }

        if ($read === 0) {
            return $noData(StaffReport::NO_DATA_UNREADABLE);
        }

        return ['check' => $minutes >= self::MINUTES ? StaffReport::PRESENCE_CONFIRMED : StaffReport::PRESENCE_CONFLICT, 'minutes' => $minutes, 'reason' => null];
    }
}
