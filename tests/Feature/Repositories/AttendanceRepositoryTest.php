<?php

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Repositories\AttendanceRepository;
use Illuminate\Support\Facades\DB;

// 3.1 / AD-14: one attendance row per (trooper, practice). The rollcall signature and the SP
// mark are independent facts with their own writes — each an update-or-create on that key touching only
// its own columns — so re-marking changes the one row in place, and recording one fact never
// wipes the other.

beforeEach(function () {
    $this->repo = app(AttendanceRepository::class);
    $this->practice = SectionPractice::factory()->create();
    $this->trooper = Trooper::factory()->create();
});

it('marks a trooper\'s practice attendance, leaving the rollcall unsigned on a new row', function () {
    $attendance = $this->repo->markPractice($this->practice, $this->trooper->id, AttendanceStatus::Present, AttendanceSource::Manual);

    expect($attendance->exists)->toBeTrue()
        ->and($attendance->trooper_id)->toBe($this->trooper->id)
        ->and($attendance->section_practice_id)->toBe($this->practice->id)
        ->and($attendance->status)->toBe(AttendanceStatus::Present)
        ->and($attendance->source)->toBe(AttendanceSource::Manual)
        ->and($attendance->rollcall)->toBe(RollcallSignature::Unsigned)
        ->and($attendance->fresh()->rollcall)->toBe(RollcallSignature::Unsigned);
});

it('signs the rollcall before the practice is marked, leaving status and source null', function () {
    $attendance = $this->repo->signRollcall($this->practice, $this->trooper->id, RollcallSignature::CannotAttend);

    $fresh = $attendance->fresh();

    expect($fresh->rollcall)->toBe(RollcallSignature::CannotAttend)
        ->and($fresh->status)->toBeNull()
        ->and($fresh->source)->toBeNull();
});

it('updates the existing row on a repeat mark for the same trooper and practice', function () {
    $first = $this->repo->markPractice($this->practice, $this->trooper->id, AttendanceStatus::Present, AttendanceSource::Manual);
    $second = $this->repo->markPractice($this->practice, $this->trooper->id, AttendanceStatus::Missed, AttendanceSource::Manual);

    expect($second->id)->toBe($first->id)
        ->and(Attendance::query()->count())->toBe(1)
        ->and(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Missed);
});

it('keeps the practice mark when the rollcall is signed afterwards', function () {
    $this->repo->markPractice($this->practice, $this->trooper->id, AttendanceStatus::Present, AttendanceSource::Manual);
    $this->repo->signRollcall($this->practice, $this->trooper->id, RollcallSignature::WillAttend);

    $row = Attendance::query()->sole();

    expect($row->status)->toBe(AttendanceStatus::Present)
        ->and($row->source)->toBe(AttendanceSource::Manual)
        ->and($row->rollcall)->toBe(RollcallSignature::WillAttend);
});

it('keeps the rollcall signature when the practice is marked afterwards', function () {
    $this->repo->signRollcall($this->practice, $this->trooper->id, RollcallSignature::WillAttend);
    $this->repo->markPractice($this->practice, $this->trooper->id, AttendanceStatus::Missed, AttendanceSource::Manual);

    $row = Attendance::query()->sole();

    expect($row->rollcall)->toBe(RollcallSignature::WillAttend)
        ->and($row->status)->toBe(AttendanceStatus::Missed)
        ->and($row->source)->toBe(AttendanceSource::Manual);
});

it('lists a practice\'s attendance rows, with the trooper loaded', function () {
    $other = Trooper::factory()->create();
    $otherPractice = SectionPractice::factory()->create();

    $this->repo->markPractice($this->practice, $this->trooper->id, AttendanceStatus::Present, AttendanceSource::Manual);
    $this->repo->signRollcall($this->practice, $other->id, RollcallSignature::WillAttend);
    $this->repo->markPractice($otherPractice, $this->trooper->id, AttendanceStatus::Missed, AttendanceSource::Manual);

    $rows = $this->repo->forPractice($this->practice);

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('trooper_id')->sort()->values()->all())->toBe(collect([$this->trooper->id, $other->id])->sort()->values()->all())
        ->and($rows->every(fn (Attendance $row) => $row->relationLoaded('trooper')))->toBeTrue();
});

it('returns an empty collection for a practice with no marks — an empty roster is valid', function () {
    expect($this->repo->forPractice($this->practice))->toBeEmpty();
});

// As for SectionPracticeRepository: the racing-insert fallback is unreachable on sqlite (the
// racer's row would sit inside createOrFirst's own savepoint and roll back with it); it was
// verified by hand against MySQL 8.4. This pins the no-race path inside a transaction.
it('updates the existing row when re-marked inside an already-open transaction, no race (3.3)', function () {
    $this->repo->markPractice($this->practice, $this->trooper->id, AttendanceStatus::Missed, AttendanceSource::Auto);

    DB::transaction(function () {
        Attendance::query()->count(); // a read first, as the audited writer's transaction does

        $this->repo->markPractice($this->practice, $this->trooper->id, AttendanceStatus::Present, AttendanceSource::Manual);
    });

    $row = Attendance::query()->sole();
    expect($row->status)->toBe(AttendanceStatus::Present)
        ->and($row->source)->toBe(AttendanceSource::Manual);
});
