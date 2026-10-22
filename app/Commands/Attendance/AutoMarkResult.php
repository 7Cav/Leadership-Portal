<?php

namespace App\Commands\Attendance;

/**
 * What {@see MarkAttendanceHandler::preMark()} did with a capture's matched troopers: `marked`
 * got an `auto` present row (written or refreshed in place), `keptManual` already had a leader's
 * mark (or `loa`), or are on leave that week, and were left untouched, `notPlaced` were no longer
 * in the practice's section, or no longer serving, and got nothing. `confirmed` means the practice was confirmed (7.8), so nothing was written at all.
 *
 * `weekLocked` means nothing was written at all: the section's weekly report for the practice's
 * week has been really sent (AD-12), so its attendance is frozen and an automatic capture may not
 * change it any more than a leader may. Every count is zero then. It wins over `confirmed`: a
 * sent week is locked whether or not its practice was confirmed.
 */
final readonly class AutoMarkResult
{
    public function __construct(
        public int $marked,
        public int $keptManual,
        public int $notPlaced,
        public bool $confirmed = false,
        public bool $weekLocked = false,
    ) {}

    public static function weekLocked(): self
    {
        return new self(marked: 0, keptManual: 0, notPlaced: 0, weekLocked: true);
    }
}
