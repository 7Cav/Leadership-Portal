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
     * The game's servers a capture reads: selected, training, with their fallback loaded. A server that is the
     * fallback of another of them is left out: it is read only in that server's place. In id order, so a run reads
     * them in the same order every time.
     *
     * @return Collection<int, GameServer>
     */
    public function trainingServersFor(Game $game): Collection;

    /**
     * The servers that name this one as their fallback.
     *
     * @return Collection<int, GameServer>
     */
    public function fallbackUsersOf(GameServer $server): Collection;

    /** Whether any selected server is assigned to the game, of either type. With none, it reads the legacy env keys. */
    public function hasSelectedFor(Game $game): bool;

    /**
     * Store what an interface listed: a new server is added unselected, a known one is renamed and no longer missing,
     * and a stored one the listing left out is flagged missing (never deselected).
     *
     * A new server takes the game the listing names (`game_slug`), if the portal has it.
     *
     * @param  list<array{external_id: string, name: string, game_slug?: string|null}>  $listed
     * @return array{added: int, missing: int}
     */
    public function storeListing(TelemetryInterface $interface, array $listed): array;

    /** Select or deselect the server, assign its game, set its type and (a CRCON server) its fallback. */
    public function update(GameServer $server, bool $selected, ?int $gameId, GameServerType $type, ?int $fallbackServerId): GameServer;

    public function recordRead(int $serverId, ?int $syncRunId, bool $succeeded, ?string $reason): void;

    /**
     * The interfaces (their values) of the stored servers a capture run failed to read, each once, in the order read.
     * Empty for a run that read only the legacy env server, which records no reads.
     *
     * @return list<string>
     */
    public function failedInterfacesOf(int $syncRunId): array;

    /**
     * The latest read of each server that has one, keyed by server id.
     *
     * @return array<int, GameServerRead>
     */
    public function lastReads(): array;
}
