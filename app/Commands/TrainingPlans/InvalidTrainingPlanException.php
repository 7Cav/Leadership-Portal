<?php

namespace App\Commands\TrainingPlans;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A training plan write or review that cannot happen as asked. Thrown before anything is written (or inside the write's
 * transaction, which rolls it back); the message is worded for the leader, so the controller shows it as a form error.
 */
class InvalidTrainingPlanException extends RuntimeException
{
    public static function notAPlatoon(): self
    {
        return new self('Only a platoon has a training plan.');
    }

    public static function monthOutOfRange(): self
    {
        return new self('A training plan can only be written for a month from the week floor up to a year ahead.');
    }

    public static function unknownWeek(string $date): self
    {
        return new self("{$date} does not open a Cav week of this month.");
    }

    public static function empty(): self
    {
        return new self('Give at least one week a training subject.');
    }

    public static function weekSent(CarbonInterface $weekStart): self
    {
        return new self("The report for the week of {$weekStart->toDateString()} is already sent, so its subject can no longer change.");
    }

    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self("The week of {$weekStart->toDateString()} is too far back to change.");
    }

    public static function noPlan(): self
    {
        return new self('This platoon has no training plan for the month.');
    }

    public static function notPending(): self
    {
        return new self('This plan is not waiting for review. Reload the page to see where it stands.');
    }

    public static function changedSinceRead(): self
    {
        return new self('The platoon changed this plan after you opened it. Reload the page and review it again.');
    }
}
