<?php

namespace App\Commands\Games;

use App\Models\Game;
use App\Models\User;
use App\Repositories\GameRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Set the 7Cav forum calendars a game's operations, classes and FTXs are filed in (Operations / Battlefield 6), so the
 * calendar sync reads them and an event filed there is the game's whatever its title says. Admin-only, gated by the
 * controller (AD-6). Each calendar is given as its id or its forum link (`https://7cav.us/calendar/46/`); a blank one,
 * one that names no calendar, or one given twice for the same kind is dropped. Saving the calendars it already has
 * records nothing; a change is one audited action (AD-16).
 */
class SetGameEventCalendars
{
    /** A calendar's forum link, `.../calendar/46/` (or the add-on's `.../events/calendar/46/`), or its bare id. */
    public const PATTERN = '~^\s*(?:(\d+)|\S*/calendar/(\d+)(?:/\S*)?)\s*$~i';

    public function __construct(
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $calendars  by kind, each calendar as an id or a forum link
     */
    public function handle(User $actor, Game $game, array $calendars): Game
    {
        $calendars = self::normalize($calendars);

        if ($game->eventCalendars() === $calendars) {
            return $game;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'game.event_calendars_set',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->games->setEventCalendars($game, $calendars),
        );
    }

    /**
     * The calendars as they are kept: every kind, each a list of ids in the order given, without a blank one, one that
     * names no calendar, or a second of the same.
     *
     * @param  array<string, mixed>  $calendars
     * @return array{operation: list<int>, class: list<int>, ftx: list<int>}
     */
    public static function normalize(array $calendars): array
    {
        $kept = [];

        foreach (Game::EVENT_KINDS as $kind) {
            $ids = [];
            foreach (is_array($calendars[$kind] ?? null) ? $calendars[$kind] : [] as $value) {
                $id = self::idOf($value);
                if ($id !== null && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            $kept[$kind] = $ids;
        }

        return $kept;
    }

    /** The calendar id a value names, from its bare id or its forum link; null when it names none. */
    public static function idOf(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! preg_match(self::PATTERN, $value, $match)) {
            return null;
        }

        $id = (int) ($match[1] !== '' ? $match[1] : $match[2]);

        return $id > 0 ? $id : null;
    }
}
