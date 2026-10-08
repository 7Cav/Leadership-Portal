<?php

namespace App\Commands\WeeklyReports;

use DateTimeImmutable;

/**
 * Send — freeze — a unit's weekly report for the Cav week containing `$week` (any moment in that
 * week; the handler maps it to `week_start` through `WeekOf`, AD-11). `$reason` is required
 * (AD-16: report sends are reason-required); `$notes` are the leader's notes exactly as confirmed
 * in the pre-send overview (5.5), frozen as given. Handled by {@see SendWeeklyReportHandler}.
 */
final readonly class SendWeeklyReport
{
    public function __construct(
        public int $unitId,
        public DateTimeImmutable $week,
        public ?string $reason,
        public ?string $notes,
    ) {}
}
