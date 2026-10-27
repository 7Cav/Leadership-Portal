<?php

namespace App\Models;

use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A game the regiment plays (#388), kept by an Admin: what it is called, what its player identifier is called to a
 * leader ("Steam ID"), and which built-in telemetry interface reads its attendance. Portal-owned (AD-22) — written
 * only by the audited `app/Commands/Games` Commands through `GameRepository`. `slug` never changes once the game
 * exists: routes and requests name the game by it. A retired game stays on every identity and capture that uses it,
 * but cannot be newly linked to a trooper (and, once games are assigned to units, #390, newly assigned).
 */
#[Fillable(['slug', 'name', 'id_label', 'interface', 'retired_at'])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // Routes and requests name a game by its slug, so changing one would break them. A query-builder update
        // bypasses this, as for any model event; `GameRepository::update()` has no slug to change.
        static::updating(function (Game $game): void {
            if ($game->isDirty('slug')) {
                throw new LogicException("A game's slug never changes (#388): {$game->getOriginal('slug')}.");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'interface' => TelemetryInterface::class,
            'retired_at' => 'immutable_datetime',
        ];
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }
}
