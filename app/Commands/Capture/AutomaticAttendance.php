<?php

namespace App\Commands\Capture;

use App\Models\Game;
use App\Repositories\GameRepository;

/**
 * Whether a game's practice attendance is read from its server automatically, decided exactly as the capture decides
 * it can read a game: {@see CaptureSources::isConfigured()} (a training server selected, or the legacy env server, with
 * its credentials set, and an interface other than none). A game that is not read has its attendance marked by hand,
 * so the leader warnings leave out what a missing identity or slot costs the automatic capture. Read-only; each game
 * is checked once per request.
 */
class AutomaticAttendance
{
    /** @var array<int, bool> */
    private array $read = [];

    public function __construct(
        private readonly CaptureSources $sources,
        private readonly GameRepository $games,
    ) {}

    public function reads(Game $game): bool
    {
        return $this->read[$game->id] ??= $this->sources->isConfigured($game);
    }

    /** {@see reads()} by game id; false for a game that does not exist. */
    public function readsId(int $gameId): bool
    {
        if (! array_key_exists($gameId, $this->read)) {
            $game = $this->games->find($gameId);
            $this->read[$gameId] = $game !== null && $this->sources->isConfigured($game);
        }

        return $this->read[$gameId];
    }
}
