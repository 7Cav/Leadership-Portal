<?php

namespace App\Commands\Attendance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The section's weekly report for the week has been sent, so its rollcall is locked: a sent report is frozen (AD-12),
 * and the marks it was built from may not change underneath it. Thrown by {@see MarkAttendanceHandler} before anything
 * is written, for the whole batch, and its own type so the rollcall controller can turn it into the plain form error a
 * leader reads beside the Save button. A week whose report was never sent stays open, however old it is.
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
