<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

/**
 * A clock change (daylight saving) in a time zone around one practice (16NOV26). A practice slot is kept in Zulu, so
 * when clocks change where the leaders live, the practice moves an hour on their own clocks unless the slot is moved.
 * The week before a change and the week of it ask the platoon leader to keep the slot or move it.
 */
final class ClockChange
{
    /**
     * The clock change in `$timezone` between the practice a week before `$practice` and the one a week after it, or
     * null when there is none (or the zone is unknown). `phase` is `before` when the change falls after this week's
     * practice (next week's is the first on the new time) and `of` when this week's practice is already on it. `shift`
     * is the change in the zone's offset from UTC in minutes: -60 when clocks go back an hour, 60 when they go forward.
     *
     * @return array{at: CarbonImmutable, phase: 'before'|'of', shift: int}|null
     */
    public static function around(string $timezone, DateTimeInterface $practice): ?array
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Exception) {
            return null;
        }

        $at = $practice->getTimestamp();
        $from = $at - 7 * 86400;
        $till = $at + 7 * 86400;
        // The first entry is the zone's state at `$from`; any after it is a change inside the span.
        $transitions = $zone->getTransitions($from, $till) ?: [];
        $before = $transitions[0]['offset'] ?? null;

        foreach (array_slice($transitions, 1) as $transition) {
            if ($transition['ts'] <= $from || $transition['ts'] > $till || $before === null || $transition['offset'] === $before) {
                $before = $transition['offset'] ?? $before;

                continue;
            }

            return [
                'at' => CarbonImmutable::createFromTimestampUTC($transition['ts']),
                'phase' => $transition['ts'] > $at ? 'before' : 'of',
                'shift' => intdiv($transition['offset'] - $before, 60),
            ];
        }

        return null;
    }

    /**
     * The slot that keeps the practice at the same time on the zone's clocks after a change of `$shift` minutes:
     * clocks going back an hour (-60) move it an hour later in Zulu. As `[weekday, startMinute]`, weekday 0 Sunday to
     * 6 Saturday, wrapping across midnight.
     *
     * @return array{0: int, 1: int}
     */
    public static function keepLocal(int $weekday, int $startMinute, int $shift): array
    {
        $minute = $weekday * 1440 + $startMinute - $shift;
        $minute = (($minute % 10080) + 10080) % 10080;

        return [intdiv($minute, 1440), $minute % 1440];
    }
}
