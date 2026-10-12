<?php

namespace App\Commands\Attendance;

/**
 * What {@see MarkAttendanceHandler::preMark()} did with a capture's matched troopers: `marked`
 * got an `auto` present row (written or refreshed in place), `keptManual` already had a leader's
 * mark (or `loa`) and were left untouched, `notPlaced` were no longer in the practice's section
 * and got nothing. `confirmed` means the practice was confirmed (7.8), so nothing was written at all.
 */
final readonly class AutoMarkResult
{
    public function __construct(
        public int $marked,
        public int $keptManual,
        public int $notPlaced,
        public bool $confirmed = false,
    ) {}
}
