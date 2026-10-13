<?php

namespace App\Commands\Attendance;

use DateTimeImmutable;

/**
 * Confirm a section's practice marks for one Cav week as reviewed by a leader (7.8): the sign-off
 * after checking what auto-capture filled and correcting what was wrong. `$week` is any moment in
 * the week; the handler maps it through `WeekOf` (AD-11). Handled by {@see ConfirmSectionPracticeHandler}.
 *
 * `$seenConfirmedAt` is the confirmation the leader's screen showed when they confirmed: its time,
 * or null for "not confirmed". When the practice holds a confirmation other than that one, someone
 * else confirmed it in the meantime, and the handler answers theirs instead of stamping a second.
 * Left `false`, nothing is compared and every confirmation re-stamps.
 */
final readonly class ConfirmSectionPractice
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
        public DateTimeImmutable|false|null $seenConfirmedAt = false,
    ) {}
}
