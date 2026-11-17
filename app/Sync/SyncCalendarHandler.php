<?php

namespace App\Sync;

use App\Integration\Forum\ForumApi;
use App\Models\Game;
use App\Models\SyncRun;
use App\Repositories\CalendarEventRepository;
use App\Repositories\CalendarOccurrence;
use App\Repositories\GameRepository;
use App\Repositories\SyncRunRepository;
use App\Sync\Calendar\ICalendar;
use App\Sync\Calendar\Occurrences;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The sole writer of the `calendar_events` cache (AD-4, AD-22, #751): reads the 7Cav forum calendar's iCalendar export
 * through {@see ForumApi::exportCalendar()}, works out when each event takes place from a week before the read to
 * {@see AHEAD_DAYS} after it ({@see Occurrences}, a weekly event once per week), and stores that through
 * {@see CalendarEventRepository::sync()}. Run on a schedule, so a roll call reads the cache and never waits on the forum.
 *
 * The week before the read is there so the running Cav week, which started at most seven days ago, is whole: a weekly
 * event that took place on its Saturday is still written, though the export no longer lists that date. What took place
 * before the read is never deleted (the export leaves out what is over); what is still to come and was not read is.
 *
 * Then it reads each forum calendar a game names ({@see Game::eventCalendars()}, Operations / Battlefield 6), whose
 * export lists only the events filed in it: that is how it learns the calendar of each event, which the whole
 * calendar's export does not say, and it stores it with the event. An event a calendar lists that the whole export
 * left out is stored too.
 *
 * An export that lists no event at all deletes nothing and makes the run `partial`: the calendar always has its weekly
 * events, so an empty one is the forum answering wrong.
 *
 * A read that fails (the forum down, an error, something that is not a calendar) records a failed run and changes
 * nothing, so the roll call keeps the last events it had and can say the calendar could not be read. The run is
 * recorded in `sync_runs` under {@see SyncRun::SOURCE_CALENDAR}.
 */
class SyncCalendarHandler
{
    /** How far past the read a run stores events: the rest of the running week, with room to spare. */
    public const AHEAD_DAYS = 14;

    /** How far before the read a run stores events: enough to hold the whole of the running Cav week. */
    public const BEHIND_DAYS = 7;

    public function __construct(
        private readonly ForumApi $forum,
        private readonly CalendarEventRepository $events,
        private readonly SyncRunRepository $syncRuns,
        private readonly GameRepository $games,
    ) {}

    /**
     * @throws Throwable the failure that stopped the run, after it has been recorded
     */
    public function handle(int|string $triggeredBy = 'system'): void
    {
        // Runs are serialized by the job's lock, so one still `in_progress` now was killed before it could record itself.
        $this->syncRuns->failOrphaned(SyncRun::SOURCE_CALENDAR);

        $run = $this->syncRuns->open(SyncRun::SOURCE_CALENDAR, $triggeredBy);

        try {
            $readAt = CarbonImmutable::now('UTC');
            $from = $readAt->subDays(self::BEHIND_DAYS);
            $to = $readAt->addDays(self::AHEAD_DAYS);

            $entries = ICalendar::parse($this->forum->exportCalendar());

            // Which forum calendar each event is filed in, from the export of each calendar a game names. An event the
            // whole export left out is taken from its calendar's.
            $calendarOf = [];
            $listed = array_flip(array_map(fn ($entry) => $entry->uid, $entries));
            $extra = [];
            foreach ($this->calendarIds() as $calendarId) {
                foreach (ICalendar::parse($this->forum->exportCalendar($calendarId)) as $entry) {
                    $calendarOf[$entry->uid] ??= $calendarId;
                    if (! isset($listed[$entry->uid])) {
                        $extra[] = $entry;
                    }
                }
            }

            $occurrences = [];
            foreach ([...$entries, ...$extra] as $entry) {
                foreach (Occurrences::of($entry, $from, $to, $readAt) as $at) {
                    $occurrences[] = new CalendarOccurrence($entry->uid, $entry->title, $at['start'], $at['end'], $entry->url, $calendarOf[$entry->uid] ?? null);
                }
            }

            // The calendar always has its weekly events, so an export with none is the forum answering wrong, not every
            // event cancelled: nothing is deleted on it, and the run is partial.
            $empty = $entries === [];
            $counts = $this->events->sync($occurrences, $readAt, $empty ? $readAt : $to);

            $this->syncRuns->complete(
                $run,
                inserted: $counts['inserted'],
                updated: $counts['updated'],
                inactivated: $counts['deleted'],
                errored: $empty ? 1 : 0,
                error: $empty ? 'The calendar export listed no events, so no stored event was deleted.' : null,
            );
        } catch (Throwable $e) {
            $this->syncRuns->fail($run, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Every forum calendar a game still played names, in order.
     *
     * @return list<int>
     */
    private function calendarIds(): array
    {
        $ids = $this->games->all()
            ->reject(fn (Game $game) => $game->isRetired())
            ->flatMap(fn (Game $game) => $game->eventCalendarIds())
            ->unique()
            ->sort()
            ->values()
            ->all();

        return array_map('intval', $ids);
    }
}
