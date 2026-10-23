<?php

namespace App\Commands\Attendance;

/**
 * What {@see MarkAttendanceHandler::preMarkMakeups()} did with the troopers seen at another
 * section's practice: `marked` now carry an `auto` makeup on their own section's practice (written,
 * or an earlier one kept in place), `kept` were left as they were: a leader's mark, an attended
 * mark, `loa`, leave covering the week, or no longer serving in a section. `locked` got nothing
 * because their own section's week may not change: its weekly report was really sent (AD-12), its
 * practice was confirmed (7.8), or the week is before `WeekOf::floor()`.
 */
final readonly class MakeupMarkResult
{
    public function __construct(
        public int $marked = 0,
        public int $kept = 0,
        public int $locked = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self($this->marked + $other->marked, $this->kept + $other->kept, $this->locked + $other->locked);
    }
}
