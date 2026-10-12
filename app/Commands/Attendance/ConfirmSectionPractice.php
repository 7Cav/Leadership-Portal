<?php

namespace App\Commands\Attendance;

use DateTimeImmutable;

/**
 * Confirm a section's practice marks for one Cav week as reviewed by a leader (7.8): the sign-off
 * after checking what auto-capture filled and correcting what was wrong. `$week` is any moment in
 * the week; the handler maps it through `WeekOf` (AD-11). Handled by {@see ConfirmSectionPracticeHandler}.
 */
final readonly class ConfirmSectionPractice
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
    ) {}
}
