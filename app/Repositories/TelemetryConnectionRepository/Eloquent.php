<?php

namespace App\Repositories\TelemetryConnectionRepository;

use App\Models\TelemetryConnection;
use App\Models\TelemetryInterface;
use App\Repositories\TelemetryConnectionRepository;
use Illuminate\Support\Str;

class Eloquent implements TelemetryConnectionRepository
{
    public function record(int $gameId, ?int $gameServerId, TelemetryInterface $interface, bool $succeeded, ?string $reason): void
    {
        $outcome = $succeeded
            ? ['last_succeeded_at' => now()]
            : ['last_failed_at' => now(), 'last_failure_reason' => Str::limit((string) $reason, 250)];

        TelemetryConnection::query()->updateOrCreate(
            ['game_id' => $gameId, 'target' => TelemetryConnection::targetFor($gameServerId, $interface)],
            ['game_server_id' => $gameServerId, 'interface' => $interface, ...$outcome],
        );
    }

    public function allByGame(): array
    {
        $byGame = [];

        foreach (TelemetryConnection::query()->orderBy('id')->get() as $connection) {
            $byGame[$connection->game_id][$connection->target] = $connection;
        }

        return $byGame;
    }
}
