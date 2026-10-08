<?php

namespace App\Models;

/**
 * Where an `attendance` mark came from (AD-14, AD-22). `Manual` is a leader marking by hand —
 * the only source until game-server auto-capture lands (sequenced last). `Auto` is that later
 * second source; manual wins over auto on conflict.
 */
enum AttendanceSource: string
{
    case Manual = 'manual';
    case Auto = 'auto';
}
