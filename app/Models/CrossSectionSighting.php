<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time a capture saw a linked trooper at another section's practice (decided 2026-10-23), kept
 * for the later Joint Service Achievement Medal candidates whether or not it changed the trooper's
 * own mark. `section_practice_id` is the host practice; `week_start` repeats its week (AD-11);
 * `seen_from`/`seen_until` are the capture window. Written only through
 * `CrossSectionSightingRepository`, once per trooper and host practice; the capture does not record
 * sightings until practice time slots exist (its window is the whole Cav week).
 */
#[Fillable(['trooper_id', 'section_practice_id', 'week_start', 'seen_from', 'seen_until', 'source'])]
class CrossSectionSighting extends Model
{
    /** The only source today: a game-server capture. */
    public const SOURCE_CAPTURE = 'capture';

    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_datetime',
            'seen_from' => 'immutable_datetime',
            'seen_until' => 'immutable_datetime',
        ];
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }

    public function practice(): BelongsTo
    {
        return $this->belongsTo(SectionPractice::class, 'section_practice_id');
    }
}
