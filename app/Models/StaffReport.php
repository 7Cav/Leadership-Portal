<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One platoon, company or battalion HQ staff member's weekly staff report: whether they attended a section
 * practice that Cav week (`attended`), what kind (`practice_type`: `sp` or `ftx` for platoon and company HQ staff, `sp`,
 * `operation`, `class` or `event` for battalion HQ staff, each named one with `ftx_name` and `ftx_date`; `office_hour`
 * only on reports sent before presence was its own question), which sections (`practices`, `[{section_id, section}]`),
 * their presence (`presence_type`: a public server hour, an office hour with `office_hour_date` and `office_hour_note`,
 * or none), the other events they attended and their notes, and what the game servers said at send (`presence_check`,
 * `presence_source` for the section practice, `public_server_check` and `public_server_minutes` for a public server hour), all frozen as sent. UNIQUE on `(trooper_id, week_start)`. A row exists only once the report is sent. The staff member
 * may send it again, with a reason, until it locks at its deadline (`WeekOf::reportDue()`): the row is rewritten and
 * the version it replaces is appended to `revisions`. Otherwise only `sheet_pushed_at` and `sheet_range`, the Google Sheet
 * push's own bookkeeping, change. Written only by
 * `StaffReportRepository`, from the audited `SendStaffReport` Command and the queued sheet push.
 */
#[Fillable(['trooper_id', 'unit_id', 'week_start', 'attended', 'practice_type', 'ftx_name', 'ftx_date', 'office_hour_date', 'office_hour_note', 'presence_type', 'practices', 'other_events', 'notes', 'presence_check', 'presence_source', 'public_server_check', 'public_server_minutes', 'public_server_reason', 'sent_at', 'sent_by'])]
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
            'revisions' => 'array',
            'public_server_minutes' => 'integer',
        ];
    }

    public const SP = 'sp';

    public const FTX = 'ftx';

    /** Battalion HQ staff: an operation, a class or another official event, each with a name and a date. */
    public const OPERATION = 'operation';

    public const CLASS_TYPE = 'class';

    public const EVENT = 'event';

    /** The types named with `ftx_name` and `ftx_date`. */
    public const NAMED_TYPES = [self::FTX, self::OPERATION, self::CLASS_TYPE, self::EVENT];

    /**
     * A battalion HQ staff member's office hour in place of the practice: no longer sent, but still read back on reports
     * sent before presence was its own question.
     */
    public const OFFICE_HOUR = 'office_hour';

    /** `presence_type`: an hour on the public server, checked against the servers marked public. */
    public const PRESENCE_PUBLIC_SERVER = 'public_server';

    /** `presence_type`: a one-hour office hour in Discord voice, with its date and note. Not checked. */
    public const PRESENCE_OFFICE_HOUR = 'office_hour';

    /** `presence_type`: neither this week. */
    public const PRESENCE_NONE = 'none';

    public const PRESENCE_TYPES = [self::PRESENCE_PUBLIC_SERVER, self::PRESENCE_OFFICE_HOUR, self::PRESENCE_NONE];

    /**
     * `presence_check`: a picked practice was captured and no picked capture saw them. Frozen at send. The same three
     * values are used by `public_server_check`: under an hour on the public servers that week is a conflict.
     */
    public const PRESENCE_CONFLICT = 'conflict';

    /** `presence_check`: a picked practice's capture saw them. */
    public const PRESENCE_CONFIRMED = 'confirmed';

    /** `presence_check`: no picked practice was captured, so there is nothing to compare. */
    public const PRESENCE_NO_DATA = 'no_data';

    /** `public_server_reason` with `no_data`: no public server is set up for the HQ's game (or it has no game). */
    public const NO_DATA_NO_SERVER = 'no_server';

    /** `public_server_reason` with `no_data`: the trooper has no game identity linked for the game. */
    public const NO_DATA_NO_IDENTITY = 'no_identity';

    /** `public_server_reason` with `no_data`: public servers are set up, but none could be read for minutes. */
    public const NO_DATA_UNREADABLE = 'unreadable';

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
