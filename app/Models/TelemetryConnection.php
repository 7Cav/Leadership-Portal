<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * How one of a game's telemetry sources last went (#183): when it last answered, and when and why it last failed.
 * Written by the capture run and by "Test connection" through `TelemetryConnectionRepository`; read by the Game server
 * connections page. The reason is the typed failure ("crcon: unreachable"), never a URL or a key (AD-23).
 */
#[Fillable(['game_id', 'target', 'game_server_id', 'interface', 'last_succeeded_at', 'last_failed_at', 'last_failure_reason'])]
class TelemetryConnection extends Model
{
    /** The key of a source within its game: `server:{id}:{interface}` for a selected server, `env:{interface}` for env. */
    public static function targetFor(?int $gameServerId, TelemetryInterface $interface): string
    {
        return $gameServerId === null ? "env:{$interface->value}" : "server:{$gameServerId}:{$interface->value}";
    }

    protected function casts(): array
    {
        return [
            'interface' => TelemetryInterface::class,
            'last_succeeded_at' => 'immutable_datetime',
            'last_failed_at' => 'immutable_datetime',
        ];
    }
}
