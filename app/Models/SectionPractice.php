<?php

namespace App\Models;

use Database\Factories\SectionPracticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One section's Section Practice for one Cav week (AD-14): the anchor every trooper-week's single
 * counting attendance mark attaches to. Portal-owned (AD-22), with one creating writer — the
 * Repository layer, driven by 3.3's audited `MarkAttendance` Command.
 *
 * `week_start` is always a Saturday 00:00 UTC (AD-11); the Repository layer refuses anything
 * else. `server_ref`/`captured_at`/`capture_source` are the auto-capture seam (AD-19), null until
 * that work lands, so they are deliberately not mass-assignable.
 */
#[Fillable(['section_id', 'week_start'])]
class SectionPractice extends Model
{
    /** @use HasFactory<SectionPracticeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_datetime',
            'captured_at' => 'immutable_datetime',
            // Demo history (#115): set only by the demo writer, never mass-assigned.
            'is_demo' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'section_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
