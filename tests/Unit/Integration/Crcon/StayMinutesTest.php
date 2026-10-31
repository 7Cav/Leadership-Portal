<?php

use App\Integration\Crcon\StayMinutes;

// #558: a player's minutes inside a practice window, from their stays.

function stayAt(string $time): DateTimeImmutable
{
    return new DateTimeImmutable("2026-10-29 {$time}", new DateTimeZone('UTC'));
}

it('clips stays to the window and adds them up', function (array $stays, int $minutes) {
    expect(StayMinutes::within($stays, stayAt('02:00'), stayAt('03:00')))->toBe($minutes);
})->with([
    'whole hour' => [[[stayAt('01:00'), stayAt('04:00')]], 60],
    'joined late' => [[[stayAt('02:20'), null]], 40],
    'left early' => [[[stayAt('01:50'), stayAt('02:39')]], 39],
    'two stays' => [[[stayAt('02:00'), stayAt('02:15')], [stayAt('02:30'), stayAt('02:50')]], 35],
    'before the window' => [[[stayAt('00:00'), stayAt('01:59')]], 0],
    'after the window' => [[[stayAt('03:00'), null]], 0],
    'none' => [[], 0],
]);

it('counts overlapping and touching stays once, so a reconnect is one stay', function () {
    $stays = [
        [stayAt('02:00'), stayAt('02:30')],
        [stayAt('02:30'), stayAt('02:45')],
        [stayAt('02:10'), stayAt('02:20')],
    ];

    expect(StayMinutes::within($stays, stayAt('02:00'), stayAt('03:00')))->toBe(45);
});

it('rounds down to whole minutes', function () {
    expect(StayMinutes::within([[stayAt('02:00:00'), stayAt('02:39:59')]], stayAt('02:00'), stayAt('03:00')))->toBe(39);
});

it('takes stays in any order', function () {
    expect(StayMinutes::within([[stayAt('02:40'), stayAt('02:50')], [stayAt('02:00'), stayAt('02:10')]], stayAt('02:00'), stayAt('03:00')))->toBe(20);
});
