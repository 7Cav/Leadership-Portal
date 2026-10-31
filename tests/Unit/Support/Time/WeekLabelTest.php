<?php

use App\Support\Time\WeekLabel;
use Carbon\CarbonImmutable;

// The label a user reads for a Cav week: its Saturday and Friday in the portal's date format, month in capitals
// ("24OCT26 to 30OCT26"). ending() keeps the Friday alone for the Google Sheet. 2026-10-24 is a Saturday.

it('names the week opening on a week_start by its Saturday and Friday', function () {
    expect(WeekLabel::range(new CarbonImmutable('2026-10-24 00:00:00', 'UTC')))->toBe('24OCT26 to 30OCT26');
});

it('names the Cav week a moment falls in, not the moment plus six days', function (string $moment, string $label) {
    expect(WeekLabel::range(new CarbonImmutable($moment, 'UTC')))->toBe($label);
})->with([
    'mid-week' => ['2026-10-27 15:00:00', '24OCT26 to 30OCT26'],
    'the Friday close' => ['2026-10-30 23:59:59', '24OCT26 to 30OCT26'],
    'the next Saturday' => ['2026-10-31 00:00:00', '31OCT26 to 06NOV26'],
]);

it('reads an offset-bearing moment at its UTC instant (AD-11)', function () {
    // Friday 23:30 at UTC-2 is Saturday 01:30 UTC: the next Cav week.
    expect(WeekLabel::range(new CarbonImmutable('2026-10-30 23:30:00', '-02:00')))->toBe('31OCT26 to 06NOV26');
});

it('crosses a month and a year end', function () {
    expect(WeekLabel::range(new CarbonImmutable('2026-12-26 00:00:00', 'UTC')))->toBe('26DEC26 to 01JAN27');
});

it('keeps the end date alone for the Google Sheet', function () {
    expect(WeekLabel::ending(new CarbonImmutable('2026-10-27 15:00:00', 'UTC')))->toBe('30OCT26');
});

it('formats a single day with the month in capitals', function () {
    expect(WeekLabel::date(new CarbonImmutable('2026-10-23 12:00:00', 'UTC')))->toBe('23OCT26');
});

it('gives the long form for titles and mid-sentence, with the Google Form\'s end date first', function () {
    $moment = new CarbonImmutable('2026-10-27 15:00:00', 'UTC');

    expect(WeekLabel::title($moment))->toBe('Week Ending 30OCT26 (24OCT26 to 30OCT26)')
        ->and(WeekLabel::phrase($moment))->toBe('week ending 30OCT26 (24OCT26 to 30OCT26)');
});
