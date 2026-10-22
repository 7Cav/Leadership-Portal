<?php

namespace App\Commands\WeeklyReports;

use DateTimeImmutable;

/**
 * Set — or, with a blank `$subject`, clear — the training topic a platoon leader gives their
 * sections for the Cav week containing `$week`. `$enforced` locks it on every section's report;
 * otherwise it is a default the section leaders may change. Handled by
 * {@see SetPlatoonTrainingTopicHandler}.
 */
final readonly class SetPlatoonTrainingTopic
{
    public function __construct(
        public int $unitId,
        public DateTimeImmutable $week,
        public ?string $subject,
        public bool $enforced = false,
    ) {}
}
