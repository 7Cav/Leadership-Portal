<?php

namespace App\Commands\WeeklyReports;

use DateTimeImmutable;

/**
 * Save the leader's work-in-progress on a unit's weekly report for the Cav week containing
 * `$week` (5.5): notes, training subject and the unaccounted troopers' reasons. The KPIs stay
 * live until the send freezes them. Handled by {@see SaveReportDraftHandler}.
 */
final readonly class SaveReportDraft
{
    /**
     * @param  list<array{trooper_id: int, reason: string}>  $unaccounted
     */
    public function __construct(
        public int $unitId,
        public DateTimeImmutable $week,
        public ?string $notes,
        public ?string $trainingSubject = null,
        public array $unaccounted = [],
        // Leave the stored training subject as it is and ignore `$trainingSubject` (#703, the roll call's close step):
        // read under the row lock, so a topic saved meanwhile is never written back over.
        public bool $keepSubject = false,
    ) {}
}
