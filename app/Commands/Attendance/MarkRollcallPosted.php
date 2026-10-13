<?php

namespace App\Commands\Attendance;

use DateTimeImmutable;

/**
 * Record that a section's rollcall for one Cav week has been posted to the forum thread (3.10). The
 * portal never posts itself (AD-20): the leader copies the generated BBCode, posts it by hand, then
 * says so here. `$week` is any moment in the week; the handler maps it through `WeekOf` (AD-11).
 * Handled by {@see MarkRollcallPostedHandler}.
 */
final readonly class MarkRollcallPosted
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
    ) {}
}
