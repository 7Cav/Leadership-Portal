<?php

namespace App\Models;

/**
 * A trooper's Section Practice mark for one week (AD-14). The values mirror the
 * `attendance.status` DB enum by hand — change both together; `AttendanceSchemaTest` checks every
 * case here is accepted by the DB.
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
