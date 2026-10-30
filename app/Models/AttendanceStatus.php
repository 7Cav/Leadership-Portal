<?php

namespace App\Models;

/**
 * A trooper's Section Practice mark for one week (AD-14). The values mirror the `attendance.status` DB enum by hand —
 * change both together; `AttendanceSchemaTest` checks every case here is accepted by the DB.
 *
 * On screen (2026-10-30): `Present` is "Attended", `Missed` "Not attended", `Makeup` "Make-up complete" (a practice made
 * up at another section's practice or by other means, which since then also covers the old "Attached"), `Excused`
 * "Excused" (the week counts for nothing, like leave) and `Loa` "LOA", derived from the LOA data and never hand-entered
 * (3.4). A row with no status yet (roll call signed, practice not marked) holds `null`, not a case of this enum.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Missed = 'missed';
    case Makeup = 'makeup';
    case Excused = 'excused';
    case Loa = 'loa';

    /** The practice was attended, at the trooper's own section or made up: what SP % counts as met. */
    public function attended(): bool
    {
        return $this === self::Present || $this === self::Makeup;
    }

    /** The week leaves the SP rate entirely: neither met nor missed. */
    public function leavesRate(): bool
    {
        return $this === self::Excused || $this === self::Loa;
    }
}
