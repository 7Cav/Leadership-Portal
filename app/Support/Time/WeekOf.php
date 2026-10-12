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
     * The start of the Cav week before the one opening at `$weekStart`.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public static function previous(CarbonInterface $weekStart): CarbonImmutable
    {
        return self::requireStart($weekStart)->subWeek();
    }

    /**
     * The start of the Cav week after the one opening at `$weekStart`.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public static function next(CarbonInterface $weekStart): CarbonImmutable
    {
        return self::requireStart($weekStart)->addWeek();
    }

    /**
     * The week whose report cycle is running at `$now`. A week's report falls due at 23:59Z on the
     * Saturday after it ends — day one of the next week — so until that deadline passes the
     * closing week is still the current one, not the fresh week that would hide a report due in a
     * few hours.
     */
    public static function reporting(DateTimeInterface $now): CarbonImmutable
    {
        $moment = CarbonImmutable::instance($now)->utc();
        $weekStart = self::start($moment);

        return $moment->lessThan(self::reportDue($weekStart->subWeek())) ? $weekStart->subWeek() : $weekStart;
    }

    /**
     * When the report for the week opening at `$weekStart` falls due: 23:59Z on the Saturday after
     * the week ends.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public static function reportDue(CarbonInterface $weekStart): CarbonImmutable
    {
        return self::requireStart($weekStart)->addWeek()->setTime(23, 59);
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
