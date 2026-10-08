<?php

namespace App\Commands\Attendance;

use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;

/**
 * One trooper's entry in a {@see MarkAttendance} batch: their Section Practice mark, their
 * rollcall signature, or both — the two independent facts an `attendance` row carries (3.1).
 * A fact left null is not touched, so a leader can sign someone's rollcall early in the week and
 * mark the practice later without either write undoing the other.
 *
 * A rollcall signature can be set back to `unsigned`, but a practice status cannot be cleared to
 * null: once the practice is marked, a correction is another status, not an un-mark.
 *
 * `loa` is refused here: LOA status is derived from LOA data (3.4), never hand-entered, so no
 * manual batch can carry it. That is only the input half of the 3.4 seam — when LOA lands, it
 * must also decide whether a manual mark may overwrite an LOA-derived row.
 */
final readonly class AttendanceMark
{
    /**
     * @throws InvalidAttendanceMarkException when neither fact is given, or the status is `loa`.
     */
    public function __construct(
        public int $trooperId,
        public ?AttendanceStatus $status = null,
        public ?RollcallSignature $rollcall = null,
    ) {
        if ($status === null && $rollcall === null) {
            throw InvalidAttendanceMarkException::nothingToRecord($trooperId);
        }

        if ($status === AttendanceStatus::Loa) {
            throw InvalidAttendanceMarkException::loaIsDerived($trooperId);
        }
    }
}
