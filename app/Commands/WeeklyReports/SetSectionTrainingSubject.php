<?php

namespace App\Commands\WeeklyReports;

use DateTimeImmutable;

/**
 * Set a section's training subject for the Cav week containing `$week` from its roll call (#703): the topic the roll
 * call's post prints is the weekly report's training subject, one value. Null or blank clears the section's own entry,
 * so the report follows its platoon's topic again (under a topic the platoon enforces, the entry is kept as it is). Handled by {@see SetSectionTrainingSubjectHandler}.
 */
final readonly class SetSectionTrainingSubject
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
        public ?string $subject,
    ) {}
}
