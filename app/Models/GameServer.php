<?php

namespace App\Models;

use Database\Factories\GameServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A game server one of the telemetry APIs lists (#389). A fetch writes `name` and `missing_since`; an Admin selects
 * it, assigns it a game and marks it training or public, through the audited `app/Commands/Servers` Commands. A
 * capture reads a game's selected training servers. Its credentials are its interface's, in env (AD-23).
 */
#[Fillable(['interface', 'external_id', 'name', 'game_id', 'type', 'selected', 'fallback_server_id', 'missing_since'])]
class GameServer extends Model
{
    /** @use HasFactory<GameServerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'interface' => TelemetryInterface::class,
            'type' => GameServerType::class,
            'selected' => 'boolean',
            'missing_since' => 'immutable_datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function fallback(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_server_id');
    }

    /** Read for its game's attendance: selected, assigned to a game, and a training server. */
    public function isReadForAttendance(): bool
    {
        return $this->selected && $this->game_id !== null && $this->type === GameServerType::Training;
    }
}
