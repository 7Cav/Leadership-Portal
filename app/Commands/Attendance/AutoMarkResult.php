<?php

namespace App\Commands\Attendance;

/**
 * What {@see MarkAttendanceHandler::preMark()} did with a capture's matched troopers: `marked`
 * got an `auto` present row (written or refreshed in place), `keptManual` already had a leader's
 * mark (or `loa`) and were left untouched, `notPlaced` were no longer in the practice's section
 * and got nothing.
 */
final readonly class AutoMarkResult
{
    public function __construct(
        public int $marked,
        public int $keptManual,
        public int $notPlaced,
    ) {}
}
