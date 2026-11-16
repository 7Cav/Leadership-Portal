<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;

/**
 * One time a 7Cav calendar event takes place, as the calendar sync hands it to {@see CalendarEventRepository::sync()}
 * (#751). `uid` is the forum's event id; a weekly event gives one of these per week, all with the same `uid`.
 * `calendarId` is the forum calendar it is filed in, when the sync read it from one a game names. Times are UTC.
 */
final readonly class CalendarOccurrence
{
    public function __construct(
        public string $uid,
        public string $title,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt = null,
        public ?string $url = null,
        public ?int $calendarId = null,
    ) {}

    /** The row's key, as `sync()` matches a stored occurrence to a read one: the event and its start, to the second. */
    public function key(): string
    {
        return $this->uid.'@'.$this->startsAt->utc()->format('Y-m-d H:i:s');
    }
}
