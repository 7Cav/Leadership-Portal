<?php

namespace App\Models;

/**
 * Where an `attendance` mark came from (AD-14, AD-22). The values mirror the `attendance.source`
 * DB enum by hand — change both together. `Manual` is a leader marking by hand —
 * the only source until game-server auto-capture lands (sequenced last). `Auto` is that later
 * second source; manual wins over auto on conflict. Paired with `AttendanceStatus`: a row whose
 * practice is not marked yet (rollcall signed only) holds `null` for both.
 */
enum AttendanceSource: string
{
    case Manual = 'manual';
    case Auto = 'auto';
}
