<?php

namespace App\Models;

use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One trooper's row against one section practice (AD-14) — UNIQUE on `(trooper_id,
 * section_practice_id)`. One counting mark per trooper-week additionally needs the writer to mark
 * only against the trooper's primary section's practice (AD-14, AD-18). Portal-owned (AD-22),
 * with one creating writer — the Repository layer, driven by 3.3's audited `MarkAttendance`
 * Command.
 *
 * Two independent facts per row, each written on its own: `rollcall` (did they sign the weekly
 * thread) and `status` + `source` (the SP mark and its source — both null until the practice is
 * marked). `rollcall_source` says who set the signature ({@see RollcallSource}): a leader by hand, or the reader
 * of the thread's replies, which never overwrites a leader's.
 */
#[Fillable(['trooper_id', 'section_practice_id', 'status', 'source', 'rollcall', 'rollcall_source'])]
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    protected $table = 'attendance';

    /** Mirrors the column's DB default, so a freshly created model reads it without a refresh. */
    protected $attributes = [
        'rollcall' => 'unsigned',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'source' => AttendanceSource::class,
            'rollcall' => RollcallSignature::class,
            'rollcall_source' => RollcallSource::class,
            // Demo history (#115): set only by the demo writer, never mass-assigned.
            'is_demo' => 'boolean',
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
