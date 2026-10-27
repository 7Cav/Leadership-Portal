<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player a capture run observed in a practice window but did not credit (Story 7.7): `trooper_id`
 * null means unmatched (no identity link, AD-19), set means linked to a trooper whose primary
 * section is elsewhere. Written only by the capture handler through `PracticeCapturePlayerRepository`,
 * replaced on every run.
 */
#[Fillable(['section_practice_id', 'game_id', 'player_name', 'platform_id', 'trooper_id'])]
class PracticeCapturePlayer extends Model
{
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function practice(): BelongsTo
    {
        return $this->belongsTo(SectionPractice::class, 'section_practice_id');
    }
}
