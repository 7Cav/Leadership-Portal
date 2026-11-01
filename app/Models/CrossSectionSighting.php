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
 * saw them; `seen_from`/`seen_until` are the capture window). The capture records one only over a practice's slot hour,
 * for a trooper on the server for the presence minimum (#558), never from a capture of the whole Cav week. A hand
 * entry a capture later sees becomes `capture`, with its window, and keeps `recorded_by`. Both go through
 * `CrossSectionSightingRepository`.
 *
 * A third source, `staff` (#448), is not a guest at all: a staff member (HQ staff or a staff department, on no section's
 * rollcall) attended this section's practice, entered by hand by them, their staff leader or an Admin (`recorded_by`).
 * It shares the table and its one-row-per-(trooper, host practice) key, but never shows on the host's rollcall, never
 * supports a makeup and never counts towards the JSAM: the repository leaves it out of every guest read.
 */
#[Fillable(['trooper_id', 'section_practice_id', 'week_start', 'seen_from', 'seen_until', 'source', 'recorded_by', 'recorded_by_admin', 'recorded_as_unit'])]
class CrossSectionSighting extends Model
{
    /** Seen by a game-server capture. */
    public const SOURCE_CAPTURE = 'capture';

    /** Added by the host section's leader on their rollcall. */
    public const SOURCE_MANUAL = 'manual';

    /** A staff member's own practice, entered by them, their staff leader or an Admin (#448). Never a guest. */
    public const SOURCE_STAFF = 'staff';

    /** The sources that make a guest at the host practice: what the rollcall, the makeups and the JSAM read. */
    public const GUEST_SOURCES = [self::SOURCE_CAPTURE, self::SOURCE_MANUAL];

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

    public function isStaff(): bool
    {
        return $this->source === self::SOURCE_STAFF;
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

    /** The unit whose billet `recorded_by`, an Admin, held when adding this guest (#379), or null. */
    public function recordedAsUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'recorded_as_unit');
    }

    /** The Admin who added this guest while acting as `recorded_by` (#379), or null. */
    public function recordedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_admin');
    }
}
