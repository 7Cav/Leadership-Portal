<?php

namespace App\Sync\Calendar;

use Carbon\CarbonImmutable;

/**
 * When a calendar event takes place in a stretch of time (#751): once, at its start, or, for a repeating event, at
 * every start its rule gives, each lasting as long as the first. Pure: the moment the calendar was read is passed in.
 *
 * The forum's export says nothing of repeating (it has no `RRULE`): it lists a repeating event once, with the date it
 * first took place, and leaves out an event once it is over. So an event the export still lists although it ended more
 * than a day before the read is taken to repeat every week, on the weekday and at the time it first took place, which
 * is what the calendar's own pages say of its repeating events ("This event recurs weekly"). The day's grace keeps an
 * event that ended an hour before the read, and is still listed until the forum drops it, from being taken for a weekly
 * one. A rule the calendar does give is followed instead.
 */
final class Occurrences
{
    /** How long after an event ends the export may still list it without it being a repeating one. */
    private const LISTED_AFTER_HOURS = 24;

    /** A guard against a rule that never reaches the stretch (a start centuries back): the most starts one event is stepped through. */
    private const MAX_STEPS = 20_000;

    /**
     * The starts and ends, in UTC, that fall from `$from` up to (not including) `$to`, earliest first.
     *
     * @return list<array{start: CarbonImmutable, end: ?CarbonImmutable}>
     */
    public static function of(CalendarEntry $entry, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $readAt): array
    {
        $recurrence = $entry->recurrence ?? (self::repeatsUnsaid($entry, $readAt) ? Recurrence::weekly() : null);
        $length = $entry->end === null ? 0 : $entry->end->getTimestamp() - $entry->start->getTimestamp();
        $occurrence = fn (CarbonImmutable $start) => [
            'start' => $start->utc(),
            'end' => $entry->end === null ? null : $start->addSeconds($length)->utc(),
        ];

        if ($recurrence === null) {
            return self::within($entry->start, $from, $to) ? [$occurrence($entry->start)] : [];
        }

        $skipped = array_map(fn (CarbonImmutable $at) => $at->getTimestamp(), $entry->exceptions);
        $found = [];
        $counted = 0;

        foreach (self::starts($entry->start, $recurrence) as $start) {
            if ($recurrence->until !== null && $start->greaterThan($recurrence->until)) {
                break;
            }

            // A rule's count includes the starts it skips (RFC 5545), so it is counted before the skip.
            if ($recurrence->count !== null && ++$counted > $recurrence->count) {
                break;
            }

            if (! $start->lessThan($to)) {
                break;
            }

            if (! $start->lessThan($from) && ! in_array($start->getTimestamp(), $skipped, true)) {
                $found[] = $occurrence($start);
            }
        }

        return $found;
    }

    /** Whether the export lists an event although it is long over, which it does only for one that repeats. */
    private static function repeatsUnsaid(CalendarEntry $entry, CarbonImmutable $readAt): bool
    {
        return ($entry->end ?? $entry->start)->lessThan($readAt->subHours(self::LISTED_AFTER_HOURS));
    }

    private static function within(CarbonImmutable $start, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return ! $start->lessThan($from) && $start->lessThan($to);
    }

    /**
     * Every start the rule gives, from the first, in order. Stepped in the zone the start was written in, so a weekly
     * event written in a zone with summer time keeps its wall clock across the change; one written in UTC keeps its
     * UTC time.
     *
     * @return iterable<CarbonImmutable>
     */
    private static function starts(CarbonImmutable $first, Recurrence $recurrence): iterable
    {
        $steps = 0;

        if ($recurrence->frequency === Recurrence::DAILY) {
            for ($n = 0; $steps++ < self::MAX_STEPS; $n++) {
                yield $first->addDays($n * $recurrence->interval);
            }

            return;
        }

        $days = $recurrence->days === [] ? [(int) $first->format('N')] : $recurrence->days;
        // The Monday of the first week, at the first start's time of day: every week's starts are counted from it.
        $monday = $first->subDays((int) $first->format('N') - 1);

        for ($week = 0; $steps < self::MAX_STEPS; $week++) {
            $base = $monday->addWeeks($week * $recurrence->interval);

            foreach ($days as $day) {
                $steps++;
                $start = $base->addDays($day - 1);

                if ($start->lessThan($first)) {
                    continue;
                }

                yield $start;
            }
        }
    }
}
