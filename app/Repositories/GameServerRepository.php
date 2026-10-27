<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\GameServer;
use App\Models\GameServerRead;
use App\Models\GameServerType;
use App\Models\TelemetryInterface;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `game_servers` and `game_server_reads` (AD-2, #389). Portal-owned (AD-22): the audited
 * `app/Commands/Servers` Commands write the listing and an Admin's choices; the capture run records its reads.
 */
interface GameServerRepository
{
    /**
     * Every stored server, with its game and fallback, by interface then name.
     *
     * @return Collection<int, GameServer>
     */
    public function all(): Collection;

    public function find(int $id): ?GameServer;

    /**
     * The game's servers a capture reads: selected, training, with their fallback loaded. In id order, so a run reads
     * them in the same order every time.
     *
     * @return Collection<int, GameServer>
     */
    public function trainingServersFor(Game $game): Collection;

    /** Whether any selected server is assigned to the game, of either type. With none, it reads the legacy env keys. */
    public function hasSelectedFor(Game $game): bool;

    /**
     * Store what an interface listed: a new server is added unselected, a known one is renamed and no longer missing,
     * and a stored one the listing left out is flagged missing (never deselected).
     *
     * @param  list<array{external_id: string, name: string}>  $listed
     * @return array{added: int, missing: int}
     */
    public function storeListing(TelemetryInterface $interface, array $listed): array;

    /** Select or deselect the server, assign its game, set its type and (a CRCON server) its fallback. */
    public function update(GameServer $server, bool $selected, ?int $gameId, GameServerType $type, ?int $fallbackServerId): GameServer;

    public function recordRead(int $serverId, ?int $syncRunId, bool $succeeded, ?string $reason): void;

    /**
     * The latest read of each server that has one, keyed by server id.
     *
     * @return array<int, GameServerRead>
     */
    public function lastReads(): array;
}
