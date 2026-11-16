<?php

namespace App\Commands\Attendance;

use DateTimeImmutable;

/**
 * Undo the last {@see MarkRollcallPosted} of a section's rollcall for one Cav week: a leader who ticked "I edited the
 * forum post" by mistake unticks it, and the stamp it replaced comes back (#766). `$week` is any moment in the week.
 * Handled by {@see UndoRollcallPostedHandler}.
 */
final readonly class UndoRollcallPosted
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
    ) {}
}
