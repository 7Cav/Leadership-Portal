<?php

namespace App\Commands\Attendance;

/**
 * What {@see MarkAttendanceHandler::withdrawMakeup()} did with an automatic makeup once the guest entry behind it was
 * removed: `Withdrawn` cleared it to unmarked; `Kept` left the row as it was (it was not, or no longer, the automatic
 * makeup: a leader's mark, `present` from a later capture, or nothing at all, or something else still supports it);
 * `Locked` changed nothing because the trooper's own week may not change (its report sent, its practice confirmed, or
 * before `WeekOf::floor()`).
 */
enum MakeupWithdrawal: string
{
    case Withdrawn = 'withdrawn';
    case Kept = 'kept';
    case Locked = 'locked';
}
