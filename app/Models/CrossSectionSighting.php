<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time a linked trooper attended another section's practice (decided 2026-10-23), kept as the Joint Service
 * Achievement Medal evidence whether or not it changed the trooper's own mark. `section_practice_id` is the host
 * practice; `week_start` repeats its week (AD-11). Two sources, one row per trooper and host practice: `manual` (the
 * host section's leader said so on their rollcall; no window, `recorded_by` is that leader), written by the audited
 * `RecordGuestAttendance` Command and removable only through `RemoveGuestAttendance`, and `capture` (the game server
 * saw them; `seen_from`/`seen_until` are the capture window). The capture does not record sightings until practice
 * time slots exist (its window is the whole Cav week), so new rows are `manual`; older `capture` rows stay. A hand
 * entry a capture later sees becomes `capture`, with its window, and keeps `recorded_by`. Both go through
 * `CrossSectionSightingRepository`.
 */
#[Fillable(['trooper_id', 'section_practice_id', 'week_start', 'seen_from', 'seen_until', 'source', 'recorded_by'])]
class CrossSectionSighting extends Model
{
    /** Seen by a game-server capture. */
    public const SOURCE_CAPTURE = 'capture';

    /** Added by the host section's leader on their rollcall. */
    public const SOURCE_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_datetime',
            'seen_from' => 'immutable_datetime',
            'seen_until' => 'immutable_datetime',
        ];
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }

    public function practice(): BelongsTo
    {
        return $this->belongsTo(SectionPractice::class, 'section_practice_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
