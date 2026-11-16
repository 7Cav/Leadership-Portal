<?php

namespace App\Repositories;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `calendar_events` (AD-2, #751), the cache of the 7Cav forum calendar. `Sync\SyncCalendarHandler`
 * is its one writer (AD-4); the roll call reads a week of it.
 */
interface CalendarEventRepository
{
    /**
     * Store what one read of the calendar found, as one transaction. Every occurrence given is written, matched to a
     * stored one by its event and start ({@see CalendarOccurrence::key()}): a new one is added, a stored one takes the
     * title, end and link read now. A stored occurrence that starts from `$from` up to `$to` and was not read is
     * deleted: the event was cancelled, moved, or stopped repeating. One that started before `$from` is kept as it is,
     * since the calendar stops listing an event once it has taken place and a week's roll call still lists it.
     *
     * @param  list<CalendarOccurrence>  $occurrences
     * @return array{inserted: int, updated: int, deleted: int}
     */
    public function sync(array $occurrences, CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * The occurrences that start from `$from` up to (not including) `$to`, earliest first, then by title.
     *
     * @return Collection<int, CalendarEvent>
     */
    public function startingBetween(CarbonImmutable $from, CarbonImmutable $to): Collection;
}
