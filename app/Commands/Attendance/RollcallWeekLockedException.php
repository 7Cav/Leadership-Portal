<?php

namespace App\Commands\Attendance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The section's weekly report for the week has been sent, so that week's rollcall is locked: a
 * sent report froze the week's KPIs (AD-12), and rewriting the marks under it would make the next
 * report's live numbers drift from the frozen ones. Thrown by {@see MarkAttendanceHandler} inside
 * its transaction, before anything is written — the whole batch is refused, signature-only and
 * empty ones too. A week whose report was never sent, or only a generated demo report (#115), stays
 * open.
 *
 * Its own type, apart from `InvalidAttendanceMarkException`, because the refusal is about the
 * week rather than the marks: the rollcall controller (3.8) answers it as a conflict the screen
 * stops retrying, not as troopers to drop from the batch.
 */
class RollcallWeekLockedException extends RuntimeException
{
    public function __construct(
        public readonly int $sectionId,
        public readonly CarbonImmutable $weekStart,
    ) {
        parent::__construct('The report for this week has been sent, so its rollcall is locked.');
    }

    public static function for(int $sectionId, CarbonInterface $weekStart): self
    {
        return new self($sectionId, CarbonImmutable::instance($weekStart));
    }
}
