<?php

namespace App\Milpac;

use App\Models\TelemetryInterface;
use App\Repositories\GameRepository;
use App\Repositories\UnitGame;
use App\Repositories\UnitRepository;

/**
 * The game a unit plays ({@see UnitGame}) when its attendance can never be read automatically (its interface is
 * {@see TelemetryInterface::Impossible}, such as Battlefield 6). The report pages then say once that all attendance is
 * self-reported, in place of a "No game server data" alert on every staff report. Null for a unit with no game, or one
 * whose game is read or just not set up yet (`none`), which keeps its alerts. Read-only; the unit tree is read once.
 */
class SelfReportedGame
{
    /** @var array<int, UnitGame>|null */
    private ?array $games = null;

    public function __construct(
        private readonly UnitRepository $units,
        private readonly GameRepository $gameRepository,
    ) {}

    /** The game's name, or null. */
    public function for(int $unitId): ?string
    {
        $this->games ??= UnitGame::resolve($this->units->tree());
        $gameId = ($this->games[$unitId] ?? null)?->gameId;
        $game = $gameId === null ? null : $this->gameRepository->find($gameId);

        return $game?->interface === TelemetryInterface::Impossible ? $game->name : null;
    }
}
