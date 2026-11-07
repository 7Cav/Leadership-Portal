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
 * `confirmed` at {@see MINUTES} or more. `conflict` under it, when every public server answered with minutes.
 * `no_data` when there is nothing to compare: no game, no linked game identity for it, no public server set up, a
 * server that could not be read or that only knows who was seen. Never blocks the send.
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

    public function check(int $trooperId, Unit $hq, CarbonImmutable $weekStart): string
    {
        $gameId = (UnitGame::resolve($this->units->tree())[$hq->id] ?? null)?->gameId;
        $game = $gameId === null ? null : $this->games->find($gameId);
        $identity = $game === null ? null : $this->identities->forTrooper($trooperId)->firstWhere('game_id', $game->id);
        $servers = $game === null ? [] : $this->sources->publicFor($game);

        if ($identity === null || $servers === []) {
            return StaffReport::PRESENCE_NO_DATA;
        }

        $platformId = mb_strtolower((string) $identity->platform_id);
        $start = $weekStart->utc()->toDateTimeImmutable();
        $end = min($weekStart->utc()->addWeek()->subSecond(), CarbonImmutable::now('UTC'))->toDateTimeImmutable();
        $minutes = 0;
        $complete = true;

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
                $complete = false;

                continue;
            }

            foreach ($presence->players as $player) {
                if (mb_strtolower($player->platformId) !== $platformId) {
                    continue;
                }
                if ($player->minutes === null) {
                    $complete = false;
                } else {
                    $minutes += $player->minutes;
                }
            }
        }

        return match (true) {
            $minutes >= self::MINUTES => StaffReport::PRESENCE_CONFIRMED,
            $complete => StaffReport::PRESENCE_CONFLICT,
            default => StaffReport::PRESENCE_NO_DATA,
        };
    }
}
