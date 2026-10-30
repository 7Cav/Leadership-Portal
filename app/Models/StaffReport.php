<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One platoon, company or battalion HQ staff member's weekly staff report: whether they attended a section
 * practice that Cav week (`attended`), what kind (`practice_type`, `sp`, `ftx` or, for battalion HQ staff, `office_hour`, with the FTX's
 * `ftx_name` and `ftx_date` or the office hour's `office_hour_date` and `office_hour_note`), which sections (`practices`, `[{section_id, section}]`), the other events they attended and their notes,
 * and what the game server's presence data said at send (`presence_check`, `presence_source`), all frozen as sent. UNIQUE on `(trooper_id, week_start)`. A row exists only once the report is sent, and is never
 * changed after, but for `sheet_pushed_at`, the Google Sheet push's own bookkeeping. Written only by
 * `StaffReportRepository`, from the audited `SendStaffReport` Command and the queued sheet push.
 */
#[Fillable(['trooper_id', 'unit_id', 'week_start', 'attended', 'practice_type', 'ftx_name', 'ftx_date', 'office_hour_date', 'office_hour_note', 'practices', 'other_events', 'notes', 'presence_check', 'presence_source', 'sent_at', 'sent_by'])]
class StaffReport extends Model
{
    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'attended' => 'boolean',
            'ftx_date' => 'immutable_date',
            'office_hour_date' => 'immutable_date',
            'practices' => 'array',
            'sheet_pushed_at' => 'immutable_datetime',
        ];
    }

    public const SP = 'sp';

    public const FTX = 'ftx';

    /** An hour of office hours in place of the hour of play: battalion HQ staff only, self-reported. */
    public const OFFICE_HOUR = 'office_hour';

    /** `presence_check`: a picked practice was captured and no picked capture saw them. Frozen at send. */
    public const PRESENCE_CONFLICT = 'conflict';

    /** `presence_check`: a picked practice's capture saw them. */
    public const PRESENCE_CONFIRMED = 'confirmed';

    /** `presence_check`: no picked practice was captured, so there is nothing to compare. */
    public const PRESENCE_NO_DATA = 'no_data';

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
