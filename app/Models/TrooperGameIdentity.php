<?php

namespace App\Models;

use Database\Factories\TrooperGameIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A trooper's linked in-game identity in one game (AD-19): the seam auto-capture resolves an
 * observed player against. Portal-owned (AD-22) — written only by an audited Command through
 * `TrooperGameIdentityRepository`, never by a `Sync/` handler, and backed up (AD-5). Decoupled
 * from `attendance`: unlinking never touches a mark already stamped from a past capture.
 */
#[Fillable(['trooper_id', 'game_id', 'player_name', 'platform_id'])]
class TrooperGameIdentity extends Model
{
    /** @use HasFactory<TrooperGameIdentityFactory> */
    use HasFactory;

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }
}
