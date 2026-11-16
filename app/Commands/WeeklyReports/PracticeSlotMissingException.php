<?php

namespace App\Commands\WeeklyReports;

use App\Commands\Practices\SectionsWithoutSlot;
use RuntimeException;

/**
 * A section's report sent while the section has no weekly practice slot. The slot is the section's regular practice
 * time, set once on the roll call and kept every week, so it is asked for whether this week's practice was held or not.
 * Which sections need one is {@see SectionsWithoutSlot}'s answer, as for the roll call's
 * warning: a line section that plays a game and has someone placed in it. Thrown before anything is written, so the
 * report controller turns it into a form error.
 */
class PracticeSlotMissingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Set your section\'s practice slot on the roll call before you send the report.');
    }
}
