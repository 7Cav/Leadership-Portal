<?php

namespace App\Sync\Calendar;

use Carbon\CarbonImmutable;

/**
 * One event read from the 7Cav calendar's iCalendar export (#751), as {@see ICalendar::parse()} gives it: the forum's
 * event id, its title, when it first takes place and ends (in the time zone the calendar wrote them in, so a weekly
 * event steps by its own wall clock), its link, how it repeats if the calendar says so, and the starts it skips.
 */
final readonly class CalendarEntry
{
    /**
     * @param  list<CarbonImmutable>  $exceptions  the starts a repeating event skips (`EXDATE`)
     */
    public function __construct(
        public string $uid,
        public string $title,
        public CarbonImmutable $start,
        public ?CarbonImmutable $end = null,
        public ?string $url = null,
        public ?Recurrence $recurrence = null,
        public array $exceptions = [],
    ) {}
}
