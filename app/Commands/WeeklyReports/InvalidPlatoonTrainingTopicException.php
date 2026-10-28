<?php

namespace App\Commands\WeeklyReports;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A platoon training topic that cannot be set: the unit is not a platoon, the week is before the
 * floor, the platoon's own report for the week is already sent, or the week's topic came from an approved training plan. Thrown before anything is written; the message is worded
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

    /** The week's topic came from the platoon's approved training plan (#380), which is where it changes. */
    public static function fromPlan(): self
    {
        return new self("This week's topic comes from the approved training plan. Change it in the plan, which then goes back for approval.");
    }

    /** The week is older than `WeekOf::floor()`, too far back to report. */
    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self("The week of {$weekStart->format('dMy')} is too far back to report.");
    }
}
