<?php

use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\InvalidAttendanceMarkException;
use App\Commands\Attendance\MarkAttendance;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use Carbon\CarbonImmutable;

// 3.3: the request shape refuses what no leader may hand-enter, before a handler ever runs.

it('requires each mark to carry a practice status, a rollcall signature, or both', function () {
    expect(fn () => new AttendanceMark(7))
        ->toThrow(InvalidAttendanceMarkException::class, 'Each trooper needs a practice mark or a rollcall signature.');

    expect((new AttendanceMark(7, AttendanceStatus::Present))->status)->toBe(AttendanceStatus::Present)
        ->and((new AttendanceMark(7, rollcall: RollcallSignature::Unsigned))->rollcall)->toBe(RollcallSignature::Unsigned);
});

it('refuses loa as a hand-entered status — LOA is derived, never marked (3.4)', function () {
    expect(fn () => new AttendanceMark(7, AttendanceStatus::Loa))
        ->toThrow(InvalidAttendanceMarkException::class, 'A trooper cannot be marked LOA by hand');
});

it('accepts every status a leader may hand-enter', function (AttendanceStatus $status) {
    expect((new AttendanceMark(7, $status))->status)->toBe($status);
})->with([AttendanceStatus::Present, AttendanceStatus::Missed, AttendanceStatus::Makeup, AttendanceStatus::Attached]);

it('refuses a batch that marks the same trooper twice', function () {
    expect(fn () => new MarkAttendance(
        1,
        CarbonImmutable::now(),
        new AttendanceMark(7, AttendanceStatus::Present),
        new AttendanceMark(7, AttendanceStatus::Missed),
    ))->toThrow(InvalidAttendanceMarkException::class, 'A trooper appears more than once in this change.');
});

it('keeps the marks as a list', function () {
    $command = new MarkAttendance(1, CarbonImmutable::now(), new AttendanceMark(7, AttendanceStatus::Present), new AttendanceMark(3, AttendanceStatus::Missed));

    expect($command->trooperIds())->toBe([7, 3]);
});
