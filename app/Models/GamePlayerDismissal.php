<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A leader's decision that an in-game player is not a member (Story 7.7, AD-19): the identifier is
 * left out of unmatched lists until it is linked to a trooper. Portal-owned (AD-22) — written only
 * through `GamePlayerDismissalRepository` by the audited dismiss Command.
 */
#[Fillable(['game_id', 'platform_id', 'player_name', 'dismissed_by'])]
class GamePlayerDismissal extends Model
{
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}
