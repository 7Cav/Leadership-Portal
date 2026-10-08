<?php

namespace App\Models;

/**
 * A trooper's Section Practice mark for one week (AD-14). Backed so the `attendance.status` DB
 * enum and this type stay in lockstep — a value neither recognises is refused at write time.
 * `Attached` credits presence to the home section when the trooper trained elsewhere; `Loa` is
 * derived from the LOA data and never hand-entered (3.4). A row with no status yet (rollcall
 * signed, practice not marked) holds `null`, not a case of this enum.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Missed = 'missed';
    case Makeup = 'makeup';
    case Attached = 'attached';
    case Loa = 'loa';
}
