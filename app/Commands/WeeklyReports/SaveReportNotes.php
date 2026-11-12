<?php

namespace App\Commands\WeeklyReports;

use DateTimeImmutable;

/**
 * Save a section's report notes and the reasons for its unaccounted troopers from the roll call's close step (#703),
 * for the Cav week containing `$week`. The training subject is left as it is. Handled by {@see SaveReportNotesHandler}.
 */
final readonly class SaveReportNotes
{
    /**
     * @param  list<array{trooper_id: int, reason: string}>  $unaccounted
     */
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
        public ?string $notes,
        public array $unaccounted = [],
    ) {}
}
