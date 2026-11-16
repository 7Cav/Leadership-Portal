<?php

namespace App\Sync\Calendar;

use Carbon\CarbonImmutable;

/**
 * How a calendar event repeats (#751): an iCalendar `RRULE` of the kinds the 7Cav calendar uses, daily or weekly,
 * every `$interval` days or weeks, on the weekdays in `$days` (ISO numbers, Monday 1 to Sunday 7; empty means the
 * weekday it first took place on), until `$until` or for `$count` times, or for ever.
 */
final readonly class Recurrence
{
    public const DAILY = 'DAILY';

    public const WEEKLY = 'WEEKLY';

    /** The iCalendar weekday codes, by ISO weekday number. */
    private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    /**
     * @param  list<int>  $days
     */
    public function __construct(
        public string $frequency,
        public int $interval = 1,
        public ?CarbonImmutable $until = null,
        public ?int $count = null,
        public array $days = [],
    ) {}

    /**
     * Every week on the weekday and at the time an event first took place, for ever: what the calendar's own pages
     * say of its repeating events ("This event recurs weekly"), which its export leaves out.
     */
    public static function weekly(): self
    {
        return new self(self::WEEKLY);
    }

    /**
     * A rule read from an `RRULE` value (`FREQ=WEEKLY;INTERVAL=1;BYDAY=SA`), or null for one this cannot follow (a
     * monthly or yearly rule), in which case the event is taken as taking place once. `$parseTime` reads `UNTIL`, which
     * is a date or a time like `DTSTART`'s.
     *
     * @param  callable(string): ?CarbonImmutable  $parseTime
     */
    public static function fromRule(string $rule, callable $parseTime): ?self
    {
        $parts = [];
        foreach (explode(';', strtoupper(trim($rule))) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $frequency = $parts['FREQ'] ?? '';

        if (! in_array($frequency, [self::DAILY, self::WEEKLY], true)) {
            return null;
        }

        $interval = isset($parts['INTERVAL']) && ctype_digit($parts['INTERVAL']) ? max(1, (int) $parts['INTERVAL']) : 1;
        $count = isset($parts['COUNT']) && ctype_digit($parts['COUNT']) ? (int) $parts['COUNT'] : null;
        $until = isset($parts['UNTIL']) && $parts['UNTIL'] !== '' ? $parseTime($parts['UNTIL']) : null;
        // An end given as a date alone takes in the whole of that day.
        if ($until !== null && preg_match('/^\d{8}$/', $parts['UNTIL']) === 1) {
            $until = $until->endOfDay();
        }

        $days = [];
        foreach (explode(',', $parts['BYDAY'] ?? '') as $day) {
            // A position before the day (`1SA`, `-1FR`) only means something in a monthly rule, so it is left off.
            $code = preg_replace('/^[+-]?\d+/', '', trim($day));
            if (isset(self::DAYS[$code])) {
                $days[] = self::DAYS[$code];
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return new self($frequency, $interval, $until, $count, $frequency === self::WEEKLY ? $days : []);
    }
}
