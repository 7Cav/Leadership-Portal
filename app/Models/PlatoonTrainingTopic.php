<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The training topic a platoon leader set for their sections for one Cav week — UNIQUE on
 * `(unit_id, week_start)`. When `enforced`, each section's report uses `subject` and cannot change
 * it; otherwise it is the default a section leader may overwrite. Portal-owned (AD-22): the
 * Repository layer is its only writer, for the audited `SetPlatoonTrainingTopic` Command.
 *
 * `week_start` is always a Saturday 00:00 UTC (AD-11). `set_by` is who set it last.
 */
#[Fillable(['unit_id', 'week_start', 'subject', 'enforced', 'set_by', 'set_by_admin'])]
class PlatoonTrainingTopic extends Model
{
    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_datetime',
            'enforced' => 'boolean',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /** The Admin who set this topic while acting as `set_by` (#379), or null. */
    public function setterAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_admin');
    }
}
