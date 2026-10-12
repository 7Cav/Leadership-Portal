<?php

use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\InvalidAttendanceMarkException;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceForbiddenException;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Attendance\RollcallWeekLockedException;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\AttendanceRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

// 3.3 / AD-14, AD-16, AD-22, AD-8: the one audited writer of `section_practices` and `attendance`.

beforeEach(function () {
    $this->section = Unit::factory()->create(['type' => UnitType::Section]);
    $this->leader = User::factory()->commanding($this->section)->create();

    // Wednesday 2026-10-07 18:00 UTC falls in the Cav week opening Saturday 2026-10-03.
    $this->moment = CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC');
    $this->weekStart = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    // Writes are refused before the week floor, which counts back from now: pin now to this week.
    CarbonImmutable::setTestNow($this->moment);

    $this->troopers = Trooper::factory()->count(3)->create(['primary_section_id' => $this->section->id]);
});

function mark(User $actor, int $sectionId, DateTimeImmutable $moment, array $marks): SectionPractice
{
    return app(MarkAttendanceHandler::class)->handle($actor, new MarkAttendance($sectionId, $moment, ...$marks));
}

it('creates the week practice row from week_of() before writing the marks', function () {
    $practice = mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
    ]);

    expect(SectionPractice::query()->count())->toBe(1)
        ->and($practice->section_id)->toBe($this->section->id)
        ->and($practice->week_start->equalTo($this->weekStart))->toBeTrue()
        ->and(Attendance::query()->sole()->section_practice_id)->toBe($practice->id);
});

it('reuses the practice row on a second submit in the same week', function () {
    $first = mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
    ]);
    $second = mark($this->leader, $this->section->id, $this->moment->addDays(2), [
        new AttendanceMark($this->troopers[1]->id, AttendanceStatus::Missed),
    ]);

    expect($second->id)->toBe($first->id)
        ->and(SectionPractice::query()->count())->toBe(1)
        ->and(Attendance::query()->count())->toBe(2);
});

it('reuses a practice row another submit already created for the section-week', function () {
    $existing = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->weekStart]);

    $practice = mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
    ]);

    expect($practice->id)->toBe($existing->id)
        ->and(SectionPractice::query()->count())->toBe(1);
});

it('updates a repeated mark for the same trooper and week rather than duplicating it', function () {
    mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Missed),
    ]);
    mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Makeup),
    ]);

    $row = Attendance::query()->sole();
    expect($row->status)->toBe(AttendanceStatus::Makeup)
        ->and($row->source)->toBe(AttendanceSource::Manual);
});

it('writes source = manual and wins over an existing auto-sourced row', function () {
    $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->weekStart]);
    Attendance::factory()->create([
        'trooper_id' => $this->troopers[0]->id,
        'section_practice_id' => $practice->id,
        'status' => AttendanceStatus::Present,
        'source' => AttendanceSource::Auto,
    ]);

    mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Attached),
    ]);

    $row = Attendance::query()->sole();
    expect($row->status)->toBe(AttendanceStatus::Attached)
        ->and($row->source)->toBe(AttendanceSource::Manual);
});

it('records a rollcall signature, a practice mark, or both, per trooper in one batch', function () {
    mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, rollcall: RollcallSignature::WillAttend),
        new AttendanceMark($this->troopers[1]->id, status: AttendanceStatus::Present),
        new AttendanceMark($this->troopers[2]->id, AttendanceStatus::Missed, RollcallSignature::CannotAttend),
    ]);

    $rows = Attendance::query()->get()->keyBy('trooper_id');

    expect($rows[$this->troopers[0]->id]->rollcall)->toBe(RollcallSignature::WillAttend)
        ->and($rows[$this->troopers[0]->id]->status)->toBeNull()
        ->and($rows[$this->troopers[0]->id]->source)->toBeNull()
        ->and($rows[$this->troopers[1]->id]->rollcall)->toBe(RollcallSignature::Unsigned)
        ->and($rows[$this->troopers[1]->id]->status)->toBe(AttendanceStatus::Present)
        ->and($rows[$this->troopers[2]->id]->rollcall)->toBe(RollcallSignature::CannotAttend)
        ->and($rows[$this->troopers[2]->id]->status)->toBe(AttendanceStatus::Missed)
        ->and($rows[$this->troopers[2]->id]->source)->toBe(AttendanceSource::Manual);
});

it('leaves the other fact untouched when a later submit records only one', function () {
    mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, rollcall: RollcallSignature::WillAttend),
    ]);
    mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, status: AttendanceStatus::Present),
    ]);

    $row = Attendance::query()->sole();
    expect($row->rollcall)->toBe(RollcallSignature::WillAttend)
        ->and($row->status)->toBe(AttendanceStatus::Present);
});

it('records exactly one audit entry per batch, targeting the practice', function () {
    $practice = mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
        new AttendanceMark($this->troopers[1]->id, AttendanceStatus::Missed),
    ]);

    $entry = AuditLogEntry::query()->sole();
    expect($entry->actor_id)->toBe($this->leader->id)
        ->and($entry->action)->toBe('attendance.marked')
        ->and($entry->target_type)->toBe('SectionPractice')
        ->and($entry->target_id)->toBe((string) $practice->id)
        ->and($entry->reason)->toBeNull()
        ->and($entry->content_digest)->toMatch('/^[0-9a-f]{64}$/')
        ->and($entry->created_at)->not->toBeNull();
});

it('digests the marks themselves, so two different batches on one practice digest differently', function () {
    SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->weekStart]);

    mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
    ]);
    mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Missed),
    ]);

    expect(AuditLogEntry::query()->pluck('content_digest')->unique())->toHaveCount(2);
});

it('digests exactly the stored practice row and every written mark', function () {
    $practice = mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[1]->id, rollcall: RollcallSignature::WillAttend),
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
    ]);

    $stored = function ($model) {
        $attributes = $model->fresh()->getAttributes();
        ksort($attributes);

        return $attributes;
    };

    expect(AuditLogEntry::query()->sole()->content_digest)->toBe(hash('sha256', json_encode([
        'target' => $stored($practice),
        'members' => Attendance::query()->orderBy('id')->get()->map($stored)->all(),
    ], JSON_THROW_ON_ERROR)));
});

it('writes the batch in trooper-id order whatever order it was submitted in (a fixed lock order)', function () {
    mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[2]->id, AttendanceStatus::Present),
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
        new AttendanceMark($this->troopers[1]->id, AttendanceStatus::Present),
    ]);

    expect(Attendance::query()->orderBy('id')->pluck('trooper_id')->all())
        ->toBe($this->troopers->pluck('id')->sort()->values()->all());
});

it('buckets the submitted moment into its Cav week through WeekOf', function (string $moment, string $weekStart) {
    $practice = mark($this->leader, $this->section->id, CarbonImmutable::parse($moment), []);

    expect($practice->week_start->equalTo(CarbonImmutable::parse($weekStart, 'UTC')))->toBeTrue();
})->with([
    'Friday 23:59:59 UTC is still the previous week' => ['2026-10-09 23:59:59 UTC', '2026-10-03 00:00:00'],
    'Saturday 00:00 UTC opens the next week' => ['2026-10-10 00:00:00 UTC', '2026-10-10 00:00:00'],
    'Friday evening in New York is already Saturday UTC' => ['2026-10-09 21:00:00 America/New_York', '2026-10-10 00:00:00'],
]);

it('creates the practice row with zero attendance for an empty batch, and audits it', function () {
    $practice = mark($this->leader, $this->section->id, $this->moment, []);

    expect($practice->exists)->toBeTrue()
        ->and(Attendance::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->sole()->target_id)->toBe((string) $practice->id);
});

it('rolls the whole batch back — practice, marks and audit — when a write fails mid-batch', function () {
    $real = app(AttendanceRepository::class);
    app()->instance(AttendanceRepository::class, new class($real) implements AttendanceRepository
    {
        private int $calls = 0;

        public function __construct(private readonly AttendanceRepository $inner) {}

        public function markPractice(SectionPractice $practice, int $trooperId, AttendanceStatus $status, AttendanceSource $source): Attendance
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('write failed mid-batch');
            }

            return $this->inner->markPractice($practice, $trooperId, $status, $source);
        }

        public function markPracticeAuto(SectionPractice $practice, int $trooperId): ?Attendance
        {
            return $this->inner->markPracticeAuto($practice, $trooperId);
        }

        public function signRollcall(SectionPractice $practice, int $trooperId, RollcallSignature $rollcall): Attendance
        {
            return $this->inner->signRollcall($practice, $trooperId, $rollcall);
        }

        public function forPractice(SectionPractice $practice): Collection
        {
            return $this->inner->forPractice($practice);
        }

        public function countingMarks(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek, bool $includeDeparted = false): Collection
        {
            return $this->inner->countingMarks($unit, $firstWeek, $lastWeek, $includeDeparted);
        }

        public function weekProgress(array $sectionIds, CarbonInterface $weekStart, array $excusedTrooperIds = []): Collection
        {
            return $this->inner->weekProgress($sectionIds, $weekStart, $excusedTrooperIds);
        }
    });

    expect(fn () => mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
        new AttendanceMark($this->troopers[1]->id, AttendanceStatus::Present),
    ]))->toThrow(RuntimeException::class, 'write failed mid-batch');

    expect(SectionPractice::query()->count())->toBe(0)
        ->and(Attendance::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('rejects a user with no scope over the section, writing nothing', function () {
    $outsider = User::factory()->create();

    expect(fn () => mark($outsider, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
    ]))->toThrow(MarkAttendanceForbiddenException::class);

    expect(SectionPractice::query()->count())->toBe(0)
        ->and(Attendance::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('rejects a leader scoped to a different section', function () {
    $otherSection = Unit::factory()->create(['type' => UnitType::Section]);
    $otherTrooper = Trooper::factory()->create(['primary_section_id' => $otherSection->id]);

    expect(fn () => mark($this->leader, $otherSection->id, $this->moment, [
        new AttendanceMark($otherTrooper->id, AttendanceStatus::Present),
    ]))->toThrow(MarkAttendanceForbiddenException::class);

    expect(SectionPractice::query()->count())->toBe(0)
        ->and(Attendance::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('reports the out-of-scope rejection as an authorization failure (a 403 for any caller)', function () {
    expect(new MarkAttendanceForbiddenException(1))->toBeInstanceOf(AuthorizationException::class);
});

it('lets a leader whose command sits higher up the tree mark the section', function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '9900/']);
    $section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'path' => '9900/9901/']);
    $trooper = Trooper::factory()->create(['primary_section_id' => $section->id]);
    $platoonLeader = User::factory()->commanding($platoon)->create();

    mark($platoonLeader, $section->id, $this->moment, [new AttendanceMark($trooper->id, AttendanceStatus::Present)]);

    expect(Attendance::query()->sole()->trooper_id)->toBe($trooper->id);
});

it('lets an admin mark any section', function () {
    $admin = User::factory()->admin()->create();

    mark($admin, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present)]);

    expect(Attendance::query()->count())->toBe(1);
});

it('rejects a trooper not placed in the section, writing nothing for the batch', function () {
    $elsewhere = Trooper::factory()->create(['primary_section_id' => null]);

    expect(fn () => mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
        new AttendanceMark($elsewhere->id, AttendanceStatus::Present),
    ]))->toThrow(InvalidAttendanceMarkException::class, (string) $elsewhere->id);

    expect(SectionPractice::query()->count())->toBe(0)
        ->and(Attendance::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('rejects a trooper id that does not exist', function () {
    expect(fn () => mark($this->leader, $this->section->id, $this->moment, [
        new AttendanceMark(999999, AttendanceStatus::Present),
    ]))->toThrow(InvalidAttendanceMarkException::class, '999999');
});

it('refuses to key a practice to a unit that is not a section, writing nothing', function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);
    $admin = User::factory()->admin()->create();

    expect(fn () => mark($admin, $platoon->id, $this->moment, [
        new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
    ]))->toThrow(InvalidAttendanceMarkException::class, "Unit {$platoon->id} is not a section");

    expect(SectionPractice::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('refuses a section id that does not exist, even for an admin (no unit is in anyone\'s scope)', function () {
    $admin = User::factory()->admin()->create();

    expect(fn () => mark($admin, 999999, $this->moment, []))
        ->toThrow(MarkAttendanceForbiddenException::class);

    expect(SectionPractice::query()->count())->toBe(0);
});

// AD-12: a week whose report has been sent is locked; the whole batch is refused before any write.

it('refuses every batch for a week whose report has been sent, writing nothing and auditing nothing', function (Closure $marks) {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->weekStart]);

    expect(fn () => mark($this->leader, $this->section->id, $this->moment, $marks($this->troopers)))
        ->toThrow(RollcallWeekLockedException::class, 'The report for this week has been sent, so its rollcall is locked.');

    expect(SectionPractice::query()->count())->toBe(0)
        ->and(Attendance::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
})->with([
    'a practice mark' => [fn ($troopers) => [new AttendanceMark($troopers[0]->id, AttendanceStatus::Present)]],
    'a signature only' => [fn ($troopers) => [new AttendanceMark($troopers[0]->id, rollcall: RollcallSignature::WillAttend)]],
    'an empty batch' => [fn ($troopers) => []],
]);

it('leaves an existing practice and its marks exactly as they were when the week is locked', function () {
    $practice = mark($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present)]);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->weekStart]);

    expect(fn () => mark($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Missed)]))
        ->toThrow(RollcallWeekLockedException::class);

    expect(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Present)
        ->and(SectionPractice::query()->sole()->id)->toBe($practice->id)
        ->and(AuditLogEntry::query()->count())->toBe(1);
});

it('keeps a week open whose report is a draft, or a generated demo report, or absent', function (Closure $report) {
    $report($this->section, $this->weekStart);

    mark($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present)]);

    expect(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Present);
})->with([
    'a draft' => [fn (Unit $section, CarbonImmutable $week) => WeeklyReport::factory()->create(['unit_id' => $section->id, 'week_start' => $week])],
    'a demo sent report (#115)' => [fn (Unit $section, CarbonImmutable $week) => WeeklyReport::factory()->sent()->create(['unit_id' => $section->id, 'week_start' => $week, 'is_demo' => true])],
    'no report' => [fn () => null],
]);

it('locks only the sent unit-week: another section\'s sent report, or another week\'s, leaves this one open', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => Unit::factory()->create(['type' => UnitType::Section])->id, 'week_start' => $this->weekStart]);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->weekStart->subWeek()]);

    mark($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present)]);

    expect(Attendance::query()->count())->toBe(1);
});

// 7.6 / AD-14, AD-22: preMark — capture as a second source through this same writer.

it('pre-marks only troopers placed in the practice section, counting what it kept and skipped', function () {
    $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->weekStart]);
    $stranger = Trooper::factory()->create(['primary_section_id' => Unit::factory()->create(['type' => UnitType::Section])->id]);
    app(AttendanceRepository::class)->markPractice($practice, $this->troopers[0]->id, AttendanceStatus::Missed, AttendanceSource::Manual);

    $result = app(MarkAttendanceHandler::class)->preMark($practice, [
        $this->troopers[2]->id, $this->troopers[0]->id, $this->troopers[1]->id, $stranger->id, $this->troopers[1]->id,
    ]);

    expect([$result->marked, $result->keptManual, $result->notPlaced])->toBe([2, 1, 1])
        ->and(Attendance::query()->where('source', 'auto')->pluck('trooper_id')->sort()->values()->all())
        ->toBe([$this->troopers[1]->id, $this->troopers[2]->id])
        ->and(Attendance::query()->where('trooper_id', $stranger->id)->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('does not pre-mark a departed trooper who keeps their last placement, counting them as not placed', function () {
    $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->weekStart]);
    $this->troopers[1]->update(['status' => TrooperStatus::Inactive]);

    $result = app(MarkAttendanceHandler::class)->preMark($practice, [$this->troopers[0]->id, $this->troopers[1]->id]);

    expect([$result->marked, $result->keptManual, $result->notPlaced])->toBe([1, 0, 1])
        ->and(Attendance::query()->pluck('trooper_id')->all())->toBe([$this->troopers[0]->id]);
});

it('writes no audit entry for an empty pre-mark even when a user triggered it', function () {
    $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->weekStart]);

    $result = app(MarkAttendanceHandler::class)->preMark($practice, [], $this->leader->id);

    expect($result->marked)->toBe(0)->and(AuditLogEntry::query()->count())->toBe(0);
});
