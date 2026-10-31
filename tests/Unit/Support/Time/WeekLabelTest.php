<?php

use App\Support\Time\WeekLabel;
use Carbon\CarbonImmutable;

// The label a user reads for a Cav week (#568): its end date, the Friday, in the forum's `dMy` convention, as the
// Google Form names it ("Week Ending 30Oct26"). 2026-10-24 is a Saturday.

it('names the week opening on a week_start by its Friday', function () {
    expect(WeekLabel::ending(new CarbonImmutable('2026-10-24 00:00:00', 'UTC')))->toBe('30Oct26');
});

it('names the Cav week a moment falls in, not the moment plus six days', function (string $moment, string $label) {
    expect(WeekLabel::ending(new CarbonImmutable($moment, 'UTC')))->toBe($label);
})->with([
    'mid-week' => ['2026-10-27 15:00:00', '30Oct26'],
    'the Friday close' => ['2026-10-30 23:59:59', '30Oct26'],
    'the next Saturday' => ['2026-10-31 00:00:00', '06Nov26'],
]);

it('reads an offset-bearing moment at its UTC instant (AD-11)', function () {
    // Friday 23:30 at UTC-2 is Saturday 01:30 UTC: the next Cav week.
    expect(WeekLabel::ending(new CarbonImmutable('2026-10-30 23:30:00', '-02:00')))->toBe('06Nov26');
});

it('crosses a month and a year end', function () {
    expect(WeekLabel::ending(new CarbonImmutable('2026-12-26 00:00:00', 'UTC')))->toBe('01Jan27');
});
