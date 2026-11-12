<?php

namespace App\Commands\WeeklyReports;

use App\Support\Time\WeekLabel;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A sent report cannot be reopened: it is not sent, or its deadline has passed and it is locked for good (AD-12).
 * The message is the leader's to read.
 */
class WeeklyReportLockedException extends RuntimeException
{
    public static function notSent(): self
    {
        return new self('This report has not been sent, so there is nothing to edit.');
    }

    public static function deadlinePassed(CarbonInterface $due): self
    {
        return new self(self::lockedAt($due));
    }

    /** The date and Zulu time the way the UI writes them: "…locked at its deadline, 10OCT26 23 59Z, …". */
    public static function lockedAt(CarbonInterface $due): string
    {
        return 'This report locked at its deadline, '.WeekLabel::date($due).' '.WeekLabel::zulu($due).'. Request an edit and a leader above you can unlock it.';
    }
}
