<?php

use App\Support\Time\PracticeWindow;
use Carbon\CarbonImmutable;

// A section practice is one hour from its start, placed in the Cav week of its start (#392, AD-11).

$week = fn () => CarbonImmutable::parse('2026-10-24 00:00:00', 'UTC'); // a Saturday

it('places each weekday in the week that opens on Saturday', function (int $weekday, string $start) use ($week) {
    expect(PracticeWindow::inWeek($week(), $weekday, 120)->start->toIso8601ZuluString())->toBe($start);
})->with([
    'Saturday opens the week' => [6, '2026-10-24T02:00:00Z'],
    'Sunday' => [0, '2026-10-25T02:00:00Z'],
    'Thursday' => [4, '2026-10-29T02:00:00Z'],
    'Friday closes it' => [5, '2026-10-30T02:00:00Z'],
]);

it('lasts one hour, across midnight Zulu', function () use ($week) {
    $window = PracticeWindow::inWeek($week(), 3, 1439);

    expect($window->start->toIso8601ZuluString())->toBe('2026-10-28T23:59:00Z')
        ->and($window->end->toIso8601ZuluString())->toBe('2026-10-29T00:59:00Z');
});

it('keeps a slot that runs past the week boundary in the week of its start', function () use ($week) {
    $window = PracticeWindow::inWeek($week(), 5, 23 * 60 + 30);

    expect($window->end->toIso8601ZuluString())->toBe('2026-10-31T00:30:00Z')
        ->and($window->weekStart()->equalTo($week()))->toBeTrue();
});

it('refuses a slot out of range and a week start that is not a Saturday', function () use ($week) {
    expect(fn () => PracticeWindow::inWeek($week(), 7, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PracticeWindow::inWeek($week(), 0, 1440))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PracticeWindow::inWeek($week()->addDay(), 0, 0))->toThrow(InvalidArgumentException::class);
});
