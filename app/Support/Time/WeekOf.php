<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

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

    /**
     * `$weekStart` itself, as its UTC instant, when it already is a week boundary — the guard for
     * a caller *handed* a `week_start` rather than deriving one. Validates rather than derives, so
     * a wrong date is refused instead of silently snapped to some other week.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public static function requireStart(CarbonInterface $weekStart): CarbonImmutable
    {
        $boundary = self::start($weekStart);

        if (! $boundary->equalTo($weekStart)) {
            throw new InvalidArgumentException(
                "week_start must be a Saturday 00:00 UTC (AD-11); got {$weekStart->toIso8601String()}.",
            );
        }

        return $boundary;
    }
}
