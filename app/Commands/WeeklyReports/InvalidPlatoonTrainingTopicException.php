<?php

namespace App\Commands\WeeklyReports;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A platoon training topic that cannot be set: the unit is not a platoon, the week is before the
 * floor, or the platoon's own report for the week is already sent. Thrown before anything is written; the message is worded
 * for the leader, so the report controller shows it as a form error.
 */
class InvalidPlatoonTrainingTopicException extends RuntimeException
{
    public static function notAPlatoon(): self
    {
        return new self('Only a platoon sets a training topic for its sections.');
    }

    public static function reportSent(): self
    {
        return new self("This platoon's report for the week is already sent, so the topic for its sections can no longer change.");
    }

    /** The week is older than `WeekOf::floor()`, too far back to report. */
    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self("The week of {$weekStart->toDateString()} is too far back to report.");
    }
}
