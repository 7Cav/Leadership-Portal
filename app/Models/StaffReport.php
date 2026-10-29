<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One platoon or company HQ staff member's weekly staff report: whether they attended a section
 * practice that Cav week (`attended`) and which (`practices`, `[{section_id, section}]`, frozen as sent). UNIQUE on
 * `(trooper_id, week_start)`. A row exists only once the report is sent, and is never changed after. Written only by
 * `StaffReportRepository`, from the audited `SendStaffReport` Command.
 */
#[Fillable(['trooper_id', 'unit_id', 'week_start', 'attended', 'practices', 'sent_at', 'sent_by'])]
class StaffReport extends Model
{
    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'attended' => 'boolean',
            'practices' => 'array',
        ];
    }

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
