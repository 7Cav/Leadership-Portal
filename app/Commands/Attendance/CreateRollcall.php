<?php

namespace App\Commands\Attendance;

use DateTimeImmutable;

/**
 * Create a section's roll call for the Cav week containing `$week` (#703, flow 2): the first step of the weekly cycle,
 * with the week's training date and topic. `$movedTo` is this week's practice start when the leader changed it from the
 * slot (null keeps the slot); `$cancelled` says practice will not take place this week. `$subject` is the training
 * topic, the weekly report's training subject; null leaves it as it is. Handled by {@see CreateRollcallHandler}.
 */
final readonly class CreateRollcall
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
        public ?DateTimeImmutable $movedTo = null,
        public bool $cancelled = false,
        public ?string $subject = null,
    ) {}
}
