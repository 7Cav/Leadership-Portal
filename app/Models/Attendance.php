<?php

namespace App\Models;

use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One trooper's mark against one section practice (AD-14) — UNIQUE on `(trooper_id,
 * section_practice_id)`, so a trooper-week holds exactly one counting mark. Portal-owned (AD-22),
 * with one creating writer — the Repository layer, driven by 3.3's audited `MarkAttendance`
 * Command.
 *
 * Two independent facts per row: `rollcall` (did they sign the weekly thread) and `status` (the
 * SP mark itself, null until the practice is marked).
 */
#[Fillable(['trooper_id', 'section_practice_id', 'status', 'source', 'rollcall'])]
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    protected $table = 'attendance';

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'source' => AttendanceSource::class,
            'rollcall' => RollcallSignature::class,
        ];
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }

    public function sectionPractice(): BelongsTo
    {
        return $this->belongsTo(SectionPractice::class);
    }
}
