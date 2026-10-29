<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One platoon, company or battalion HQ staff member's weekly staff report: whether they attended a section
 * practice that Cav week (`attended`), what kind (`practice_type`, `sp` or `ftx`, with the FTX's `ftx_name` and
 * `ftx_date`), which sections (`practices`, `[{section_id, section}]`), the other events they attended and their notes,
 * all frozen as sent. UNIQUE on `(trooper_id, week_start)`. A row exists only once the report is sent, and is never
 * changed after, but for `sheet_pushed_at`, the Google Sheet push's own bookkeeping. Written only by
 * `StaffReportRepository`, from the audited `SendStaffReport` Command and the queued sheet push.
 */
#[Fillable(['trooper_id', 'unit_id', 'week_start', 'attended', 'practice_type', 'ftx_name', 'ftx_date', 'practices', 'other_events', 'notes', 'sent_at', 'sent_by'])]
class StaffReport extends Model
{
    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'attended' => 'boolean',
            'ftx_date' => 'immutable_date',
            'practices' => 'array',
            'sheet_pushed_at' => 'immutable_datetime',
        ];
    }

    public const SP = 'sp';

    public const FTX = 'ftx';

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }

    /** The HQ the trooper was placed on when the week ended. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
