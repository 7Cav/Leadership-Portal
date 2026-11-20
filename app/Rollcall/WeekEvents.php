<?php

namespace App\Rollcall;

use App\Models\CalendarEvent;
use App\Models\Game;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Unit;
use App\Repositories\CalendarEventRepository;
use App\Repositories\GameRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;

/**
 * The events on the 7Cav calendar that a section's roll call lists for a Cav week (#751): those that start in the week,
 * Saturday 0000Z to Saturday 0000Z a week later, whose title carries a tag of the game the section plays, its own or the nearest
 * unit's above it ({@see UnitRepository::gameOf()}, {@see Game::marksEvent()}). Read from the cache the calendar sync
 * keeps, never from the forum, so the page does not wait on it. Only listed: nothing here marks anyone as having been at
 * one.
 *
 * What it answers ({@see forSection()}), which the page shows and the post prints as `{{events}}`:
 * - `state`: `listed`, `no_game` (the section plays no game, so none can be its game's), or `unavailable` (the calendar
 *   has never been read, so the portal cannot say). Only `listed` has events.
 * - `game`: the game's name, for "No HLL events this week". Null for `no_game`.
 * - `items`: `[{name, start, url}]`, earliest first: the title as the calendar has it, the start as an ISO 8601 UTC time,
 *   and the forum's page for the event, when it gave one.
 * - `readAt`: when the calendar was last read cleanly (ISO 8601), and `lastReadFailed`: whether the read after that
 *   failed, so the page can say the list may be out of date.
 *
 * The week's report freezes this answer when it is sent (`SendWeeklyReportHandler`), so the post keeps the events it
 * had. The staff report asks it for an HQ's unit too (#553), to offer the week's events as the FTX: any unit works, by
 * the game it plays.
 */
final class WeekEvents
{
    public const LISTED = 'listed';

    public const NO_GAME = 'no_game';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly UnitRepository $units,
        private readonly GameRepository $games,
        private readonly CalendarEventRepository $events,
        private readonly SyncRunRepository $syncRuns,
    ) {}

    /**
     * @return array{state: string, game: ?string, items: list<array{name: string, start: string, url: ?string}>, readAt: ?string, lastReadFailed: bool}
     */
    public function forSection(Unit $section, CarbonImmutable $weekStart): array
    {
        $played = $this->units->gameOf($section->id);
        $game = $played === null ? null : $this->games->find($played->gameId);

        if ($game === null) {
            return ['state' => self::NO_GAME, 'game' => null, 'items' => [], 'readAt' => null, 'lastReadFailed' => false];
        }

        $read = $this->syncRuns->latestSuccessful(SyncRun::SOURCE_CALENDAR);

        if ($read === null) {
            return ['state' => self::UNAVAILABLE, 'game' => $game->name, 'items' => [], 'readAt' => null, 'lastReadFailed' => false];
        }

        $start = $weekStart->utc()->startOfDay();
        $items = $this->events->startingBetween($start, $start->addDays(7))
            ->filter(fn (CalendarEvent $event) => $game->marksEvent($event->title))
            ->map(fn (CalendarEvent $event) => [
                'name' => $event->title,
                'start' => $event->starts_at->utc()->toIso8601ZuluString(),
                'url' => $event->url,
            ])
            ->values()
            ->all();

        return [
            'state' => self::LISTED,
            'game' => $game->name,
            'items' => $items,
            'readAt' => $read->finished_at?->utc()->toIso8601ZuluString(),
            'lastReadFailed' => $this->syncRuns->latestFinished(SyncRun::SOURCE_CALENDAR)?->status === SyncRunStatus::Failure,
        ];
    }
}
