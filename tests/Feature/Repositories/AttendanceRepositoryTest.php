<?php

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\AttendanceRepository;
use App\Repositories\CountingMark;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SectionWeekProgress;
use Carbon\CarbonImmutable;
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

// 5.1: the KPI service's one read of attendance — each assigned trooper's counting marks (their
// primary section's practice, AD-14) for a span of whole weeks.

function countingFixture(): array
{
    $section = Unit::factory()->create(['path' => '900/']);
    $trooper = Trooper::factory()->create();
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return [$section, $trooper];
}

it('reads one counting mark per held practice of the trooper\'s primary section in the span', function () {
    [$section, $trooper] = countingFixture();
    $week = CarbonImmutable::parse('2026-06-27 00:00:00', 'UTC');
    $held = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => $week]);
    $unmarked = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => $week->subWeek()]);
    SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => $week->subWeeks(2)]); // before the span
    $this->repo->markPractice($held, $trooper->id, AttendanceStatus::Makeup, AttendanceSource::Manual);
    $this->repo->markPractice($this->practice, $trooper->id, AttendanceStatus::Present, AttendanceSource::Manual); // another section

    $marks = $this->repo->countingMarks($section, $week->subWeek(), $week);

    expect($marks->all())->toEqual([
        new CountingMark($trooper->id, $week->subWeek(), null, RollcallSignature::Unsigned, held: false),
        new CountingMark($trooper->id, $week, AttendanceStatus::Makeup, RollcallSignature::Unsigned, held: true, source: AttendanceSource::Manual),
    ]);
});

it('marks a practice held once anyone in the section has an SP mark, not on signatures alone', function () {
    [$section, $trooper] = countingFixture();
    $colleague = Trooper::factory()->create();
    $colleague->forceFill(['primary_section_id' => $section->id])->save();
    $week = CarbonImmutable::parse('2026-06-27 00:00:00', 'UTC');
    $signedOnly = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => $week->subWeek()]);
    $marked = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => $week]);
    $this->repo->signRollcall($signedOnly, $trooper->id, RollcallSignature::WillAttend);
    $this->repo->signRollcall($signedOnly, $colleague->id, RollcallSignature::CannotAttend);
    $this->repo->markPractice($marked, $colleague->id, AttendanceStatus::Missed, AttendanceSource::Manual);

    $marks = $this->repo->countingMarks($section, $week->subWeek(), $week)
        ->where('trooperId', $trooper->id)->values();

    expect($marks->map(fn (CountingMark $m) => [$m->weekStart->toDateString(), $m->status, $m->held])->all())->toBe([
        ['2026-06-20', null, false],
        ['2026-06-27', null, true],
    ]);
});

it('carries who recorded each counting mark, for the roster cell\'s source (2.8)', function () {
    [$section, $trooper] = countingFixture();
    $week = CarbonImmutable::parse('2026-06-27 00:00:00', 'UTC');
    $auto = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => $week]);
    $this->repo->markPractice($auto, $trooper->id, AttendanceStatus::Present, AttendanceSource::Auto);

    expect($this->repo->countingMarks($section, $week, $week)->sole()->source)->toBe(AttendanceSource::Auto);
});

it('reads no counting marks for an inactive trooper', function () {
    [$section, $trooper] = countingFixture();
    $trooper->update(['status' => 'inactive']);
    $week = CarbonImmutable::parse('2026-06-27 00:00:00', 'UTC');
    $practice = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => $week]);
    $this->repo->markPractice($practice, $trooper->id, AttendanceStatus::Missed, AttendanceSource::Manual);

    expect($this->repo->countingMarks($section, $week, $week))->toBeEmpty();
});

it('reads a departed trooper\'s marks only when asked to include them (2.8)', function () {
    [$section, $trooper] = countingFixture();
    $week = CarbonImmutable::parse('2026-06-27 00:00:00', 'UTC');
    $practice = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => $week]);
    $this->repo->markPractice($practice, $trooper->id, AttendanceStatus::Present, AttendanceSource::Manual);
    $trooper->update(['status' => 'inactive']);

    expect($this->repo->countingMarks($section, $week, $week))->toBeEmpty()
        ->and($this->repo->countingMarks($section, $week, $week, includeDeparted: true)->sole()->status)->toBe(AttendanceStatus::Present);
});

it('refuses a counting-mark span that does not start and end on week boundaries', function () {
    [$section] = countingFixture();
    $week = CarbonImmutable::parse('2026-06-27 00:00:00', 'UTC');

    $this->repo->countingMarks($section, $week, $week->addHour());
})->throws(InvalidArgumentException::class);

it('counts each section\'s week progress in one pass: strength, signed, marked and held', function () {
    $week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    [$busy, $quiet, $empty] = Unit::factory()->count(3)->create(['type' => UnitType::Section]);
    $busyTroopers = Trooper::factory()->count(3)->create(['primary_section_id' => $busy->id]);
    Trooper::factory()->count(2)->create(['primary_section_id' => $quiet->id]);
    $practices = app(SectionPracticeRepository::class);
    $repo = app(AttendanceRepository::class);

    $practice = $practices->findOrCreate($busy->id, $week);
    $repo->signRollcall($practice, $busyTroopers[0]->id, RollcallSignature::WillAttend);
    $repo->signRollcall($practice, $busyTroopers[1]->id, RollcallSignature::Unsigned);
    $repo->markPractice($practice, $busyTroopers[0]->id, AttendanceStatus::Present, AttendanceSource::Manual);
    $repo->markPractice($practice, $busyTroopers[1]->id, AttendanceStatus::Loa, AttendanceSource::Auto);

    $progress = $repo->weekProgress([$busy->id, $quiet->id, $empty->id], $week);

    expect($progress->keys()->all())->toEqualCanonicalizing([$busy->id, $quiet->id])
        ->and($progress->get($busy->id))->toEqual(new SectionWeekProgress($busy->id, 3, 1, 2, true))
        ->and($progress->get($quiet->id))->toEqual(new SectionWeekProgress($quiet->id, 2, 0, 0, false))
        ->and($repo->weekProgress([], $week))->toBeEmpty();
});
