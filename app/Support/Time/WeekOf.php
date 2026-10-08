<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * The single time → `week_start` mapping (AD-11). The Cav week runs from Saturday 00:00 UTC up
 * to (not including) the next Saturday 00:00 UTC, one fixed portal-wide boundary — never a
 * per-unit timezone. Rollcall, attendance bucketing and every roll-up must derive `week_start`
 * here and nowhere else, so a leaf and its parent can never disagree on which week a moment
 * belongs to.
 *
 * Display formatting (the forum's `30May26`) is a presentation concern, not this helper's.
 */
final class WeekOf
{
    /**
     * The Saturday 00:00 UTC that opens the Cav week containing `$moment`. The input is
     * normalized to UTC before bucketing, so an offset-bearing time lands in the week of its
     * UTC instant, not of its wall-clock date. The input is never mutated.
     */
    public static function start(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)
            ->utc()
            ->startOfWeek(CarbonInterface::SATURDAY);
    }
}
