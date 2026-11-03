<?php

namespace App\Commands\WeeklyReports;

use DateTimeImmutable;

/**
 * Reopen a unit's sent weekly report for the Cav week containing `$week` so it can be edited and sent again, until it
 * locks at its deadline. `$reason` is required, as on a send (AD-16). Handled by {@see ReopenWeeklyReportHandler}.
 */
final readonly class ReopenWeeklyReport
{
    public function __construct(
        public int $unitId,
        public DateTimeImmutable $week,
        public ?string $reason,
    ) {}
}
