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
 * `loa` is refused here: LOA status is derived from LOA data (3.4), never hand-entered, so no
 * manual batch can carry it. That keeps the seam for 3.4 clean — when LOA lands it decides which
 * rows are LOA-derived without ever competing with a manual mark of the same value.
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
