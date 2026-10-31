<?php

namespace App\Repositories\GameServerRepository;

use App\Models\Game;
use App\Models\GameServer;
use App\Models\GameServerRead;
use App\Models\GameServerType;
use App\Models\TelemetryInterface;
use App\Repositories\GameServerRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Eloquent implements GameServerRepository
{
    public function all(): Collection
    {
        return GameServer::query()->with(['game', 'fallback'])->orderBy('interface')->orderBy('name')->orderBy('id')->get();
    }

    public function find(int $id): ?GameServer
    {
        return GameServer::query()->with(['game', 'fallback'])->find($id);
    }

    public function trainingServersFor(Game $game): Collection
    {
        $training = fn ($query) => $query->where('game_id', $game->id)->where('selected', true)->where('type', GameServerType::Training);

        return GameServer::query()
            ->with('fallback')
            ->where($training)
            // A training server that is another's fallback is the same game server seen through BattleMetrics: it is
            // read only when that server cannot be, never as a server of its own that must also answer.
            ->whereNotIn('id', GameServer::query()->where($training)->whereNotNull('fallback_server_id')->select('fallback_server_id'))
            ->orderBy('id')
            ->get();
    }

    public function fallbackUsersOf(GameServer $server): Collection
    {
        return GameServer::query()->where('fallback_server_id', $server->id)->orderBy('id')->get();
    }

    public function hasSelectedFor(Game $game): bool
    {
        return GameServer::query()->where('game_id', $game->id)->where('selected', true)->exists();
    }

    public function storeListing(TelemetryInterface $interface, array $listed): array
    {
        return DB::transaction(function () use ($interface, $listed) {
            $stored = GameServer::query()->where('interface', $interface)->lockForUpdate()->get()->keyBy('external_id');
            $added = 0;

            foreach ($listed as $server) {
                // The column is 255 wide, and a server's name is whatever its owner typed.
                $name = Str::limit($server['name'], 250);
                $known = $stored->get($server['external_id']);

                if ($known === null) {
                    // The game the API names is only a starting point for a new server; an Admin's choice is never overwritten.
                    $gameId = ($server['game_slug'] ?? null) === null ? null : Game::query()->where('slug', $server['game_slug'])->value('id');
                    GameServer::query()->create(['interface' => $interface, 'external_id' => $server['external_id'], 'name' => $name, 'game_id' => $gameId]);
                    $added++;
                } else {
                    $known->update(['name' => $name, 'missing_since' => null]);
                }
            }

            $listedIds = array_column($listed, 'external_id');
            $missing = GameServer::query()
                ->where('interface', $interface)
                ->whereNotIn('external_id', $listedIds)
                ->whereNull('missing_since')
                ->update(['missing_since' => now()]);

            return ['added' => $added, 'missing' => $missing];
        });
    }

    public function update(GameServer $server, bool $selected, ?int $gameId, GameServerType $type, ?int $fallbackServerId): GameServer
    {
        $server->update(['selected' => $selected, 'game_id' => $gameId, 'type' => $type, 'fallback_server_id' => $fallbackServerId]);

        return $server->load(['game', 'fallback']);
    }

    public function recordRead(int $serverId, ?int $syncRunId, bool $succeeded, ?string $reason): void
    {
        GameServerRead::query()->create([
            'game_server_id' => $serverId,
            'sync_run_id' => $syncRunId,
            'succeeded' => $succeeded,
            'reason' => $reason === null ? null : Str::limit($reason, 250),
            'read_at' => now(),
        ]);
    }

    public function failedInterfacesOf(int $syncRunId): array
    {
        return GameServerRead::query()
            ->join('game_servers', 'game_servers.id', '=', 'game_server_reads.game_server_id')
            ->where('game_server_reads.sync_run_id', $syncRunId)
            ->where('game_server_reads.succeeded', false)
            ->orderBy('game_server_reads.id')
            ->pluck('game_servers.interface')
            ->unique()
            ->values()
            ->all();
    }

    public function lastReads(): array
    {
        $latest = GameServerRead::query()->selectRaw('max(id)')->groupBy('game_server_id');

        return GameServerRead::query()->whereIn('id', $latest)->get()->keyBy('game_server_id')->all();
    }
}
