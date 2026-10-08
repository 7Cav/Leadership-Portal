<?php

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Repositories\AttendanceRepository;

// 3.1 / AD-14: one attendance row per (trooper, practice). `record()` is an upsert on that key,
// so re-marking a trooper changes the row in place rather than adding a second counting mark.

beforeEach(function () {
    $this->practice = SectionPractice::factory()->create();
    $this->trooper = Trooper::factory()->create();
});

it('records a trooper\'s attendance against a practice', function () {
    $attendance = app(AttendanceRepository::class)->record(
        $this->practice, $this->trooper->id, AttendanceSource::Manual, AttendanceStatus::Present, RollcallSignature::WillAttend,
    );

    expect($attendance->exists)->toBeTrue()
        ->and($attendance->trooper_id)->toBe($this->trooper->id)
        ->and($attendance->section_practice_id)->toBe($this->practice->id)
        ->and($attendance->status)->toBe(AttendanceStatus::Present)
        ->and($attendance->source)->toBe(AttendanceSource::Manual)
        ->and($attendance->rollcall)->toBe(RollcallSignature::WillAttend);
});

it('updates the existing row on a repeat mark for the same trooper and practice', function () {
    $repo = app(AttendanceRepository::class);

    $first = $repo->record($this->practice, $this->trooper->id, AttendanceSource::Manual, AttendanceStatus::Present, RollcallSignature::WillAttend);
    $second = $repo->record($this->practice, $this->trooper->id, AttendanceSource::Manual, AttendanceStatus::Missed, RollcallSignature::WillAttend);

    expect($second->id)->toBe($first->id)
        ->and(Attendance::query()->count())->toBe(1)
        ->and(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Missed);
});

it('records a rollcall signature before the practice is marked, with a null status', function () {
    $attendance = app(AttendanceRepository::class)->record(
        $this->practice, $this->trooper->id, AttendanceSource::Manual, null, RollcallSignature::CannotAttend,
    );

    expect($attendance->fresh()->status)->toBeNull()
        ->and($attendance->fresh()->rollcall)->toBe(RollcallSignature::CannotAttend);
});

it('lists a practice\'s attendance rows, with the trooper loaded', function () {
    $repo = app(AttendanceRepository::class);
    $other = Trooper::factory()->create();
    $otherPractice = SectionPractice::factory()->create();

    $repo->record($this->practice, $this->trooper->id, AttendanceSource::Manual, AttendanceStatus::Present, RollcallSignature::WillAttend);
    $repo->record($this->practice, $other->id, AttendanceSource::Manual, AttendanceStatus::Makeup, RollcallSignature::Unsigned);
    $repo->record($otherPractice, $this->trooper->id, AttendanceSource::Manual, AttendanceStatus::Missed, RollcallSignature::Unsigned);

    $rows = $repo->forPractice($this->practice);

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('trooper_id')->sort()->values()->all())->toBe(collect([$this->trooper->id, $other->id])->sort()->values()->all())
        ->and($rows->first()->relationLoaded('trooper'))->toBeTrue();
});

it('returns an empty collection for a practice with no marks — an empty roster is valid', function () {
    expect(app(AttendanceRepository::class)->forPractice($this->practice))->toBeEmpty();
});
