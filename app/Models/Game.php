<?php

namespace App\Models;

use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A game the regiment plays (#388), kept by an Admin: what it is called, which kinds of player identifier it asks for ("Steam ID", "Epic ID", #428), and which built-in telemetry interface reads its attendance. Portal-owned (AD-22) — written
 * only by the audited `app/Commands/Games` Commands through `GameRepository`. `slug` never changes once the game
 * exists: routes and requests name the game by it. A retired game stays on every identity and capture that uses it,
 * but cannot be newly linked to a trooper (and, once games are assigned to units, #390, newly assigned).
 */
#[Fillable(['slug', 'name', 'id_kinds', 'interface', 'retired_at'])]
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
            'id_kinds' => 'array',
            'interface' => TelemetryInterface::class,
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** The kind a new identity takes when none is chosen, and the one every identity from before kinds existed took: the first. */
    public function defaultIdKind(): string
    {
        return $this->id_kinds[0];
    }

    public function hasIdKind(string $kind): bool
    {
        return in_array($kind, $this->id_kinds, true);
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }
}
