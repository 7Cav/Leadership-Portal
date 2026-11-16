<?php

use App\Support\Time\ClockChange;

// 16NOV26: clocks in the US went back an hour on 01NOV26 at 0600Z (0200 EDT to 0100 EST). A slot kept in Zulu moves an
// hour on a US leader's clock; the week before and the week of the change ask to keep or move it.

it('finds the change in the week after a practice, before it happens', function () {
    $change = ClockChange::around('America/New_York', new DateTimeImmutable('2026-10-29 02:00:00', new DateTimeZone('UTC')));

    expect($change['at']->toIso8601ZuluString())->toBe('2026-11-01T06:00:00Z')
        ->and($change['phase'])->toBe('before')
        ->and($change['shift'])->toBe(-60);
});

it('finds the change in the week before a practice, once it happened', function () {
    $change = ClockChange::around('America/New_York', new DateTimeImmutable('2026-11-05 02:00:00', new DateTimeZone('UTC')));

    expect($change['phase'])->toBe('of')->and($change['shift'])->toBe(-60);
});

it('finds nothing a fortnight away, in a zone without daylight saving, or for an unknown zone', function () {
    $later = new DateTimeImmutable('2026-11-12 02:00:00', new DateTimeZone('UTC'));

    expect(ClockChange::around('America/New_York', $later))->toBeNull()
        ->and(ClockChange::around('UTC', new DateTimeImmutable('2026-10-29 02:00:00', new DateTimeZone('UTC'))))->toBeNull()
        ->and(ClockChange::around('Not/AZone', $later))->toBeNull();
});

it('finds the European change a week earlier, and clocks going forward', function () {
    expect(ClockChange::around('Europe/Amsterdam', new DateTimeImmutable('2026-10-22 19:00:00', new DateTimeZone('UTC')))['at']->toIso8601ZuluString())->toBe('2026-10-25T01:00:00Z')
        ->and(ClockChange::around('America/New_York', new DateTimeImmutable('2027-03-11 02:00:00', new DateTimeZone('UTC')))['shift'])->toBe(60);
});

it('moves the slot to keep the same local time, across midnight and the week', function (int $weekday, int $minute, int $shift, array $kept) {
    expect(ClockChange::keepLocal($weekday, $minute, $shift))->toBe($kept);
})->with([
    'clocks back: an hour later in Zulu' => [4, 120, -60, [4, 180]],
    'clocks forward: an hour earlier' => [4, 180, 60, [4, 120]],
    'past midnight' => [3, 1410, -60, [4, 30]],
    'before Sunday midnight' => [0, 30, 60, [6, 1410]],
]);
