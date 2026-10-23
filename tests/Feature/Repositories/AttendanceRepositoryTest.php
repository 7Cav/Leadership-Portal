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

it('reads only the span\'s practices of the unit\'s sections to decide whether a practice was held', function () {
    [$section, $trooper] = countingFixture();
    $week = CarbonImmutable::parse('2026-06-27 00:00:00', 'UTC');
    $practice = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => $week]);
    $this->repo->markPractice($practice, $trooper->id, AttendanceStatus::Present, AttendanceSource::Manual);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $marks = $this->repo->countingMarks($section, $week, $week);
    $sql = str_replace(['"', '`'], '', collect(DB::getQueryLog())->pluck('query')->implode("\n"));
    DB::disableQueryLog();

    // The held subquery is the left-joined select aliased `held`; unbounded, it would group the
    // whole attendance history on every KPI read.
    preg_match('/left join \((select .*?)\) as held/s', $sql, $held);

    expect($marks->sole()->held)->toBeTrue()
        ->and($held)->not->toBeEmpty()
        ->and($held[1])->toContain('section_practices.week_start between')
        ->and($held[1])->toContain('section_practices.section_id in');
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
        ->and($progress->get($busy->id))->toEqual(new SectionWeekProgress($busy->id, 3, 1, 2, true, onLoa: 1, unsigned: 1))
        ->and($progress->get($quiet->id))->toEqual(new SectionWeekProgress($quiet->id, 2, 0, 0, false, onLoa: 0, unsigned: 2))
        ->and($repo->weekProgress([], $week))->toBeEmpty();
});

it('counts an excused trooper as signed and marked once, row or not, and never as holding the practice (3.4)', function () {
    $week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    [$noRow, $signedOnly, $marked] = Trooper::factory()->count(3)->create(['primary_section_id' => $section->id]);
    $outsider = Trooper::factory()->create();
    $repo = app(AttendanceRepository::class);

    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, $week);
    $repo->signRollcall($practice, $signedOnly->id, RollcallSignature::CannotAttend);
    $repo->markPractice($practice, $marked->id, AttendanceStatus::Present, AttendanceSource::Manual);

    expect($repo->weekProgress([$section->id], $week)->get($section->id)->marked)->toBe(1)
        ->and($repo->weekProgress([$section->id], $week, [$noRow->id, $signedOnly->id, $marked->id, $outsider->id])->get($section->id))
        ->toEqual(new SectionWeekProgress($section->id, 3, 3, 3, true, onLoa: 3, unsigned: 0));

    // Nobody marked by hand: the excused troopers alone do not make the practice held.
    $quiet = Unit::factory()->create(['type' => UnitType::Section]);
    $away = Trooper::factory()->create(['primary_section_id' => $quiet->id]);

    expect($repo->weekProgress([$quiet->id], $week, [$away->id])->get($quiet->id))
        ->toEqual(new SectionWeekProgress($quiet->id, 1, 1, 1, false, onLoa: 1, unsigned: 0));
});

it("reads when each section's rollcall was marked posted with its week progress, in the same query", function () {
    $week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    [$posted, $open] = Unit::factory()->count(2)->create(['type' => UnitType::Section]);
    Trooper::factory()->create(['primary_section_id' => $posted->id]);
    Trooper::factory()->create(['primary_section_id' => $open->id]);
    $practice = app(SectionPracticeRepository::class)->findOrCreate($posted->id, $week);
    $practice->forceFill(['rollcall_posted_at' => '2026-10-06 18:30:00'])->save();
    app(SectionPracticeRepository::class)->findOrCreate($posted->id, $week->subWeek())->forceFill(['rollcall_posted_at' => '2026-09-29 10:00:00'])->save();
    $repo = app(AttendanceRepository::class);

    DB::enableQueryLog();
    $progress = $repo->weekProgress([$posted->id, $open->id], $week);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($progress->get($posted->id)->postedAt?->toIso8601String())->toBe('2026-10-06T18:30:00+00:00')
        ->and($progress->get($open->id)->postedAt)->toBeNull();
});

// 7.6 / AD-14, AD-22: capture's write — present/auto, never over a leader's mark.

it('auto-marks a trooper with no row as present from the auto source', function () {
    $row = $this->repo->markPracticeAuto($this->practice, $this->trooper->id);

    expect($row->status)->toBe(AttendanceStatus::Present)
        ->and($row->source)->toBe(AttendanceSource::Auto)
        ->and(Attendance::query()->count())->toBe(1);
});

it('returns null and changes nothing when the row is manual', function () {
    $this->repo->markPractice($this->practice, $this->trooper->id, AttendanceStatus::Missed, AttendanceSource::Manual);

    expect($this->repo->markPracticeAuto($this->practice, $this->trooper->id))->toBeNull();

    $row = Attendance::query()->sole();
    expect($row->status)->toBe(AttendanceStatus::Missed)->and($row->source)->toBe(AttendanceSource::Manual);
});

it('refreshes an auto row in place and leaves a loa row alone', function () {
    $this->repo->markPractice($this->practice, $this->trooper->id, AttendanceStatus::Missed, AttendanceSource::Auto);
    $this->repo->markPracticeAuto($this->practice, $this->trooper->id);

    expect(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Present);

    $other = Trooper::factory()->create();
    $this->repo->markPractice($this->practice, $other->id, AttendanceStatus::Loa, AttendanceSource::Auto);

    expect($this->repo->markPracticeAuto($this->practice, $other->id))->toBeNull()
        ->and(Attendance::query()->where('trooper_id', $other->id)->sole()->status)->toBe(AttendanceStatus::Loa);
});

it('turns a demo row real when it auto-marks over it', function () {
    $this->repo->markPractice($this->practice, $this->trooper->id, AttendanceStatus::Missed, AttendanceSource::Auto);
    Attendance::query()->update(['is_demo' => true]);

    $this->repo->markPracticeAuto($this->practice, $this->trooper->id);

    expect(Attendance::query()->sole()->is_demo)->toBeFalse();
});

// 2026-10-23: capture's makeup write, for a trooper seen at another section's practice.

it('writes an auto makeup over nothing, a rollcall-only row or an auto miss, and keeps an auto makeup in place', function (?AttendanceStatus $status, ?AttendanceSource $source) {
    if ($status !== null || $source !== null) {
        $this->repo->markPractice($this->practice, $this->trooper->id, $status, $source);
    }
    $this->repo->signRollcall($this->practice, $this->trooper->id, RollcallSignature::WillAttend);

    $row = $this->repo->markMakeupAuto($this->practice, $this->trooper->id);

    expect($row->status)->toBe(AttendanceStatus::Makeup)
        ->and($row->source)->toBe(AttendanceSource::Auto)
        ->and(Attendance::query()->sole()->rollcall)->toBe(RollcallSignature::WillAttend);
})->with([
    'no mark' => [null, null],
    'auto missed' => [AttendanceStatus::Missed, AttendanceSource::Auto],
    'auto makeup' => [AttendanceStatus::Makeup, AttendanceSource::Auto],
]);

it('never writes a makeup over a manual mark, loa, or an attended mark', function (AttendanceStatus $status, AttendanceSource $source) {
    $this->repo->markPractice($this->practice, $this->trooper->id, $status, $source);

    expect($this->repo->markMakeupAuto($this->practice, $this->trooper->id))->toBeNull();

    $row = Attendance::query()->sole();
    expect($row->status)->toBe($status)->and($row->source)->toBe($source);
})->with([
    'manual missed' => [AttendanceStatus::Missed, AttendanceSource::Manual],
    'manual makeup' => [AttendanceStatus::Makeup, AttendanceSource::Manual],
    'auto present' => [AttendanceStatus::Present, AttendanceSource::Auto],
    'auto attached' => [AttendanceStatus::Attached, AttendanceSource::Auto],
    'loa' => [AttendanceStatus::Loa, AttendanceSource::Auto],
]);

it('clears an automatic makeup to unmarked, keeping the row and its signature', function () {
    $this->repo->signRollcall($this->practice, $this->trooper->id, RollcallSignature::WillAttend);
    $this->repo->markMakeupAuto($this->practice, $this->trooper->id);

    $row = $this->repo->clearMakeupAuto($this->practice, $this->trooper->id);

    expect($row->status)->toBeNull()
        ->and($row->source)->toBeNull()
        ->and(Attendance::query()->sole()->status)->toBeNull()
        ->and(Attendance::query()->sole()->rollcall)->toBe(RollcallSignature::WillAttend);
});

it('never clears anything but an automatic makeup', function (AttendanceStatus $status, AttendanceSource $source) {
    $this->repo->markPractice($this->practice, $this->trooper->id, $status, $source);

    expect($this->repo->clearMakeupAuto($this->practice, $this->trooper->id))->toBeNull();

    $row = Attendance::query()->sole();
    expect($row->status)->toBe($status)->and($row->source)->toBe($source);
})->with([
    'manual makeup' => [AttendanceStatus::Makeup, AttendanceSource::Manual],
    'auto present' => [AttendanceStatus::Present, AttendanceSource::Auto],
    'auto missed' => [AttendanceStatus::Missed, AttendanceSource::Auto],
    'loa' => [AttendanceStatus::Loa, AttendanceSource::Auto],
]);

it('clears nothing where the trooper has no row', function () {
    expect($this->repo->clearMakeupAuto($this->practice, $this->trooper->id))->toBeNull()
        ->and(Attendance::query()->count())->toBe(0);
});

it('lets a later capture of the trooper\'s own practice replace the auto makeup with present', function () {
    $this->repo->markMakeupAuto($this->practice, $this->trooper->id);

    expect($this->repo->markPracticeAuto($this->practice, $this->trooper->id)->status)->toBe(AttendanceStatus::Present);
});

it('does not hold a practice whose only marks are automatic makeups, on Home or in the counting marks', function () {
    $week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $section = Unit::factory()->create(['type' => UnitType::Section, 'path' => '901/']);
    [$visitor, $stayedHome] = Trooper::factory()->count(2)->create(['primary_section_id' => $section->id]);
    $practices = app(SectionPracticeRepository::class);
    $practice = $practices->findOrCreate($section->id, $week);
    $this->repo->markMakeupAuto($practice, $visitor->id);

    expect($this->repo->weekProgress([$section->id], $week)->get($section->id))
        ->toEqual(new SectionWeekProgress($section->id, 2, 0, 1, false, onLoa: 0, unsigned: 2))
        ->and($this->repo->countingMarks($section, $week, $week)->pluck('held')->unique()->all())->toBe([false]);

    // A leader's mark of anyone, a hand-marked makeup included, holds it.
    $this->repo->markPractice($practice, $stayedHome->id, AttendanceStatus::Makeup, AttendanceSource::Manual);

    expect($this->repo->weekProgress([$section->id], $week)->get($section->id)->held)->toBeTrue()
        ->and($this->repo->countingMarks($section, $week, $week)->pluck('held')->unique()->all())->toBe([true]);
});
