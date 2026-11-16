<?php

namespace App\Commands\WeeklyReports;

use App\Support\Time\WeekLabel;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A section's report in the week before a clock change or the week of it, before its platoon leader kept or moved the
 * practice slot for it (16NOV26).
 */
class ClockChangeNotConfirmedException extends RuntimeException
{
    public function __construct(public readonly CarbonImmutable $changeAt)
    {
        parent::__construct('Clocks change on '.WeekLabel::date($changeAt).'. Your platoon leader confirms the practice slot on the roll call before this report can be sent.');
    }
}
