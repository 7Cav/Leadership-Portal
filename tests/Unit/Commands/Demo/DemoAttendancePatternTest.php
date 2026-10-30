<?php

use App\Commands\Demo\DemoAttendancePattern;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use Carbon\CarbonImmutable;

// #115: the demo pattern is a pure function of (section, week, troopers) — reruns reproduce it.

$troopers = range(101, 112);
$week = CarbonImmutable::parse('2026-09-26 00:00:00', 'UTC');

it('is deterministic per section and week, whatever order the troopers come in', function () use ($troopers, $week) {
    $first = DemoAttendancePattern::forSectionWeek(7, $week, $troopers);

    expect(DemoAttendancePattern::forSectionWeek(7, $week, array_reverse($troopers)))->toEqual($first)
        ->and(array_column($first, 'trooper_id'))->toBe($troopers);
});

it('varies between weeks and between sections', function () use ($troopers, $week) {
    $base = DemoAttendancePattern::forSectionWeek(7, $week, $troopers);

    expect(DemoAttendancePattern::forSectionWeek(7, $week->subWeek(), $troopers))->not->toEqual($base)
        ->and(DemoAttendancePattern::forSectionWeek(8, $week, $troopers))->not->toEqual($base);
});

it('keeps the same at-risk trooper every week, missing three weeks in four', function () use ($troopers, $week) {
    $atRisk = DemoAttendancePattern::atRiskTrooper(7, $troopers);

    $marks = collect(range(0, 7))->map(fn (int $back) => collect(DemoAttendancePattern::forSectionWeek(7, $week->subWeeks($back), $troopers))
        ->firstWhere('trooper_id', $atRisk));

    expect($marks->filter(fn ($m) => $m['status'] === AttendanceStatus::Missed)->count())->toBe(6)
        ->and($marks->filter(fn ($m) => $m['status'] === AttendanceStatus::Present && $m['rollcall'] === RollcallSignature::WillAttend)->count())->toBe(2);
});

it('never hand-enters loa or excused', function () use ($troopers, $week) {
    $statuses = collect(range(0, 30))->flatMap(fn (int $back) => array_column(DemoAttendancePattern::forSectionWeek(3, $week->subWeeks($back), $troopers), 'status'));

    expect($statuses->contains(AttendanceStatus::Loa))->toBeFalse()
        ->and($statuses->contains(AttendanceStatus::Makeup))->toBeTrue()
        ->and($statuses->contains(AttendanceStatus::Excused))->toBeFalse();
});
