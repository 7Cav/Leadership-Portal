<?php

use App\Sync\Calendar\CalendarEntry;
use App\Sync\Calendar\ICalendar;
use App\Sync\Calendar\Occurrences;
use App\Sync\Calendar\Recurrence;
use Carbon\CarbonImmutable;

// #751: reading the 7Cav calendar's iCalendar export, and working out when each event takes place. The fixture is shaped
// like the forum's own export (tests/Fixtures/forum/calendar-export.ics): descriptions written with their line breaks as
// they are, a folded title, a repeating event listed once with the date it first took place.

function calFixture(): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/forum/calendar-export.ics');
}

/** @return array<string, CalendarEntry> */
function calEntries(?string $source = null): array
{
    $entries = [];
    foreach (ICalendar::parse($source ?? calFixture()) as $entry) {
        $entries[$entry->uid] = $entry;
    }

    return $entries;
}

function calUtc(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

/** @return list<string> the starts, as `Y-m-d H:i` UTC */
function calStarts(CalendarEntry $entry, string $from, string $to, string $readAt): array
{
    return array_map(fn (array $at) => $at['start']->format('Y-m-d H:i'), Occurrences::of($entry, calUtc($from), calUtc($to), calUtc($readAt)));
}

it('reads every event with an id, a title and a start, and leaves out a cancelled one and one with no title', function () {
    expect(array_keys(calEntries()))->toBe(['1064', '4075', '1232', '4088', '3926']);
});

it('reads the title, start, end and link of an event, its escapes undone', function () {
    $arma = calEntries()['4088'];

    expect($arma->title)->toBe('Arma Reforger | ACE | Advanced Rotary Course, Part 1; Ground')
        ->and($arma->url)->toBe('https://7cav.us/events/4088/');
});

it('joins a folded line back onto the one it continues', function () {
    expect(calEntries()['4075']->title)->toBe('SQUAD | Operation "Paper Trail" | 15NOV26 2000z');
});

it('reads a description written with raw line breaks as the description, so its lines never become the event', function () {
    $squad = calEntries()['1064'];

    // The description's own "SUMMARY:" and "DTSTART:" lines come before the event's real ones, which win.
    expect($squad->title)->toBe('SQUAD | Squad Saturday @1900Z')
        ->and($squad->start->format('Y-m-d H:i e'))->toBe('2023-01-07 19:00 UTC')
        ->and($squad->end->format('Y-m-d H:i'))->toBe('2023-01-07 20:00')
        ->and($squad->url)->toBe('https://7cav.us/events/1064/');
});

it('does not read the lines of an alarm inside an event as the event', function () {
    expect(calEntries()['1232']->title)->toBe("[HLL] Operation Trenched n' Tuesday @ 2300Z - Every Tuesday");
});

it('reads a time in the zone its TZID names, and a time ending in Z as UTC', function () {
    $arma = calEntries()['4088'];

    expect($arma->start->utc()->format('Y-m-d H:i'))->toBe('2026-11-21 19:30')
        ->and($arma->end->utc()->format('Y-m-d H:i'))->toBe('2026-11-21 20:30')
        ->and(ICalendar::time('20261121T190000Z')->format('Y-m-d H:i e'))->toBe('2026-11-21 19:00 UTC')
        // No zone and no Z: read as UTC. A zone PHP does not know: UTC too.
        ->and(ICalendar::time('20261121T190000')->getTimezone()->getName())->toBe('UTC')
        ->and(ICalendar::time('20261121T190000', ['TZID' => 'Eastern Standard Time'])->getTimezone()->getName())->toBe('UTC')
        // A date alone is the start of the day.
        ->and(ICalendar::time('20261121')->format('Y-m-d H:i'))->toBe('2026-11-21 00:00')
        ->and(ICalendar::time('20261131T190000Z'))->toBeNull()
        ->and(ICalendar::time('soon'))->toBeNull();
});

it('reads lines ending in CRLF the same, and a fold made with a tab', function () {
    $source = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:9\r\nDTSTART:20261121T190000Z\r\nSUMMARY:[HLL] Long\r\n\t title\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    expect(calEntries($source)['9']->title)->toBe('[HLL] Long title');
});

it('reads a weekly rule with its weekdays, interval, end and count, and gives none for a rule it cannot follow', function () {
    $rule = Recurrence::fromRule('FREQ=WEEKLY;INTERVAL=2;BYDAY=SA,TU;COUNT=4', fn (string $value) => ICalendar::time($value));

    expect($rule->frequency)->toBe(Recurrence::WEEKLY)
        ->and($rule->interval)->toBe(2)
        ->and($rule->days)->toBe([2, 6])
        ->and($rule->count)->toBe(4)
        ->and(Recurrence::fromRule('FREQ=WEEKLY;UNTIL=20261124T235959Z', fn (string $value) => ICalendar::time($value))->until->format('Y-m-d H:i:s'))->toBe('2026-11-24 23:59:59')
        ->and(Recurrence::fromRule('FREQ=MONTHLY;BYDAY=1SA', fn (string $value) => ICalendar::time($value)))->toBeNull();
});

describe('when an event takes place', function () {
    it('gives a one-off event once, in the stretch it starts in, and nothing outside it', function () {
        $paperTrail = calEntries()['4075'];

        expect(calStarts($paperTrail, '2026-11-14', '2026-11-21', '2026-11-14 21:00'))->toBe(['2026-11-15 20:00'])
            ->and(calStarts($paperTrail, '2026-11-21', '2026-11-28', '2026-11-14 21:00'))->toBe([]);

        $at = Occurrences::of($paperTrail, calUtc('2026-11-14'), calUtc('2026-11-21'), calUtc('2026-11-14 21:00'))[0];
        expect($at['end']->format('Y-m-d H:i'))->toBe('2026-11-15 22:00');
    });

    it('takes an event the export still lists long after it took place as weekly, once per week, at its first time', function () {
        $squad = calEntries()['1064'];
        $trenched = calEntries()['1232'];

        // The Cav week of 14NOV26 to 20NOV26.
        expect(calStarts($squad, '2026-11-14', '2026-11-21', '2026-11-14 21:00'))->toBe(['2026-11-14 19:00'])
            ->and(calStarts($squad, '2026-11-14', '2026-11-28', '2026-11-14 21:00'))->toBe(['2026-11-14 19:00', '2026-11-21 19:00'])
            ->and(calStarts($trenched, '2026-11-14', '2026-11-21', '2026-11-14 21:00'))->toBe(['2026-11-18 00:00']);

        $at = Occurrences::of($squad, calUtc('2026-11-14'), calUtc('2026-11-21'), calUtc('2026-11-14 21:00'))[0];
        expect($at['end']->format('Y-m-d H:i'))->toBe('2026-11-14 20:00');
    });

    it('does not take an event that ended less than a day before the read for a weekly one', function () {
        $entry = new CalendarEntry('7', '[HLL] Just over', calUtc('2026-11-14 10:00'), calUtc('2026-11-14 12:00'));

        expect(calStarts($entry, '2026-11-14', '2026-11-28', '2026-11-14 21:00'))->toBe(['2026-11-14 10:00'])
            ->and(calStarts($entry, '2026-11-14', '2026-11-28', '2026-11-15 13:00'))->toBe(['2026-11-14 10:00', '2026-11-21 10:00']);
    });

    it('follows a weekly rule the calendar gives, up to its end and without the starts it skips', function () {
        $tunnel = calEntries()['3926'];

        expect(calStarts($tunnel, '2026-11-01', '2026-12-31', '2026-11-14 21:00'))->toBe(['2026-11-03 23:00', '2026-11-17 23:00', '2026-11-24 23:00']);
    });

    it('follows a rule on several weekdays every other week, for a count that includes its first start', function () {
        $entry = new CalendarEntry('8', '[HLL] Twice', calUtc('2026-11-14 19:00'), null, null, new Recurrence(Recurrence::WEEKLY, 2, null, 3, [2, 6]));

        // Sat 14NOV, Tue 24NOV (the next week is skipped), Sat 28NOV: three, then the count is spent.
        expect(calStarts($entry, '2026-11-01', '2027-01-31', '2026-11-14 18:00'))->toBe(['2026-11-14 19:00', '2026-11-24 19:00', '2026-11-28 19:00']);
    });

    it('follows a daily rule', function () {
        $entry = new CalendarEntry('9', '[HLL] Daily', calUtc('2026-11-14 19:00'), null, null, new Recurrence(Recurrence::DAILY, 1, calUtc('2026-11-16 23:59')));

        expect(calStarts($entry, '2026-11-01', '2026-11-30', '2026-11-14 18:00'))->toBe(['2026-11-14 19:00', '2026-11-15 19:00', '2026-11-16 19:00']);
    });

    it('steps a weekly event written in a zone with summer time by its wall clock', function () {
        $start = ICalendar::time('20261024T190000', ['TZID' => 'America/New_York']);
        $entry = new CalendarEntry('10', '[HLL] Eastern', $start, null, null, Recurrence::weekly());

        // 1900 Eastern is 2300Z in summer time and 0000Z once the clocks go back on 01NOV26.
        expect(calStarts($entry, '2026-10-24', '2026-11-09', '2026-10-24 12:00'))->toBe(['2026-10-24 23:00', '2026-10-31 23:00', '2026-11-08 00:00']);
    });
});
