<?php

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\Loa;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\AttendanceRepository;
use App\Repositories\CountingWindow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// 3.8 / AD-8, AD-14, AD-16, AD-22: the rollcall screen's autosave writes through the audited
// MarkAttendance Command and answers JSON.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '910/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '910/911/']);
    $this->sibling = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '910/912/']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->trooper = Trooper::factory()->create();
    $this->trooper->forceFill(['primary_section_id' => $this->section->id])->save();
});

function saveRollcall(User $user, int $unitId, array $marks, string $week = '2026-10-03')
{
    return test()->actingAs($user)->postJson("/rollcall/{$unitId}", ['week' => $week, 'marks' => $marks]);
}

it('writes a practice mark and a signature as one manual, audited row', function () {
    saveRollcall($this->leader, $this->section->id, [
        ['trooper_id' => $this->trooper->id, 'status' => 'makeup', 'rollcall' => 'will_attend'],
    ])->assertOk()->assertJsonStructure(['savedAt', 'atRisk', 'makeupOwed']);

    $row = Attendance::query()->sole();
    expect($row->status)->toBe(AttendanceStatus::Makeup)
        ->and($row->source)->toBe(AttendanceSource::Manual)
        ->and($row->rollcall)->toBe(RollcallSignature::WillAttend)
        ->and($row->sectionPractice->week_start->equalTo($this->week))->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'attendance.marked')->count())->toBe(1);
});

it('records a signature without touching the practice mark, and the reverse', function () {
    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'present']])->assertOk();
    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'rollcall' => 'cannot_attend']])->assertOk();

    $row = Attendance::query()->sole();
    expect($row->status)->toBe(AttendanceStatus::Present)->and($row->rollcall)->toBe(RollcallSignature::CannotAttend);
});

it('updates the one counting row on a re-save, never duplicating it, and audits each save', function () {
    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'missed']])->assertOk();
    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'makeup']])->assertOk();

    expect(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Makeup)
        ->and(SectionPractice::query()->count())->toBe(1)
        ->and(AuditLogEntry::query()->where('action', 'attendance.marked')->count())->toBe(2);
});

it('overrides an auto mark with a manual one (AD-22)', function () {
    $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week]);
    Attendance::factory()->create(['trooper_id' => $this->trooper->id, 'section_practice_id' => $practice->id, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Auto]);

    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'missed']])->assertOk();

    expect(Attendance::query()->sole()->source)->toBe(AttendanceSource::Manual);
});

it('accepts an empty batch, creating the practice with nobody marked', function () {
    saveRollcall($this->leader, $this->section->id, [])->assertOk();

    expect(SectionPractice::query()->sole()->section_id)->toBe($this->section->id)
        ->and(Attendance::query()->count())->toBe(0);
});

it('corrects a past week', function () {
    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'present']], '2026-09-30')->assertOk();

    expect(SectionPractice::query()->sole()->week_start->toDateString())->toBe('2026-09-26');
});

it('refuses a future week', function () {
    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'present']], '2026-10-10')
        ->assertStatus(422);

    expect(SectionPractice::query()->count())->toBe(0);
});

it('refuses loa and unknown values as input', function (array $mark) {
    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, ...$mark]])
        ->assertStatus(422)->assertJsonStructure(['message']);

    expect(Attendance::query()->count())->toBe(0);
})->with([
    'loa' => [['status' => 'loa']],
    'unknown status' => [['status' => 'excused_late']],
    'the old attached' => [['status' => 'attached']],
    'unknown signature' => [['rollcall' => 'maybe']],
    'nothing to record' => [[]],
]);

it('refuses a trooper placed in another section', function () {
    $elsewhere = Trooper::factory()->create();
    $elsewhere->forceFill(['primary_section_id' => $this->sibling->id])->save();

    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $elsewhere->id, 'status' => 'present']])->assertStatus(422);

    expect(Attendance::query()->count())->toBe(0);
});

it('refuses a section outside the leader\'s scope with a 403 (AD-8)', function () {
    $other = Trooper::factory()->create();
    $other->forceFill(['primary_section_id' => $this->sibling->id])->save();

    saveRollcall($this->leader, $this->sibling->id, [['trooper_id' => $other->id, 'status' => 'present']])->assertForbidden();

    expect(Attendance::query()->count())->toBe(0);
});

it('answers a unit outside the leader\'s scope with 403 whether or not it exists', function () {
    saveRollcall($this->leader, 999999, [])->assertForbidden();
});

it('refuses a unit that is not a section, even for an admin', function () {
    saveRollcall(User::factory()->admin()->create(), $this->platoon->id, [])->assertStatus(422);

    expect(SectionPractice::query()->count())->toBe(0);
});

it('names the troopers a batch was refused for, so the screen can save everyone else', function () {
    $moved = Trooper::factory()->create();
    $moved->forceFill(['primary_section_id' => $this->sibling->id])->save();

    saveRollcall($this->leader, $this->section->id, [
        ['trooper_id' => $this->trooper->id, 'status' => 'present'],
        ['trooper_id' => $moved->id, 'status' => 'present'],
    ])->assertStatus(422)->assertJsonPath('rejected', [$moved->id])->assertJsonPath('reason', 'not_placed');
});

it('refuses a practice mark for a trooper on leave, saying why, and still saves their signature (3.4)', function () {
    Loa::factory()->create(['trooper_id' => $this->trooper->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-20']);

    // A leave synced after the page loaded: the screen sent a mark the Command refuses. Nothing is written.
    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'present', 'rollcall' => 'cannot_attend']])
        ->assertStatus(422)
        ->assertJsonPath('rejected', [$this->trooper->id])
        ->assertJsonPath('reason', 'loa_covered');
    expect(Attendance::query()->count())->toBe(0);

    // The screen resends the signature on its own, which is a separate fact and is accepted.
    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'rollcall' => 'cannot_attend']])->assertOk();

    $row = Attendance::query()->sole();
    expect($row->rollcall)->toBe(RollcallSignature::CannotAttend)->and($row->status)->toBeNull();
});

it('rejects a malformed batch', function (array $payload) {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}", $payload)->assertStatus(422);

    expect(SectionPractice::query()->count())->toBe(0);
})->with([
    'no marks key' => [['week' => '2026-10-03']],
    'malformed week' => [['week' => 'soon', 'marks' => []]],
    'duplicate trooper' => [fn () => ['week' => '2026-10-03', 'marks' => [['trooper_id' => $this->trooper->id, 'status' => 'present'], ['trooper_id' => $this->trooper->id, 'status' => 'missed']]]],
]);

it('lets a leader whose command sits higher up the tree mark a child section', function () {
    $platoonLeader = User::factory()->commanding($this->platoon)->create();

    saveRollcall($platoonLeader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'present']])->assertOk();
});

it('still answers saved when only the at-risk refresh fails, so the screen never resends a committed batch', function () {
    // The KPI service reads counting marks after the write; make only that read fail.
    $real = app(AttendanceRepository::class);
    app()->instance(AttendanceRepository::class, new class($real) implements AttendanceRepository
    {
        public function __construct(private readonly AttendanceRepository $inner) {}

        public function markPractice(SectionPractice $practice, int $trooperId, AttendanceStatus $status, AttendanceSource $source): Attendance
        {
            return $this->inner->markPractice($practice, $trooperId, $status, $source);
        }

        public function markPracticeAuto(SectionPractice $practice, int $trooperId): ?Attendance
        {
            return $this->inner->markPracticeAuto($practice, $trooperId);
        }

        public function markMakeupAuto(SectionPractice $practice, int $trooperId): ?Attendance
        {
            return $this->inner->markMakeupAuto($practice, $trooperId);
        }

        public function clearMakeupAuto(SectionPractice $practice, int $trooperId): ?Attendance
        {
            return $this->inner->clearMakeupAuto($practice, $trooperId);
        }

        public function practicesWithAutoMakeup(int $trooperId, CarbonInterface $weekStart): Collection
        {
            return $this->inner->practicesWithAutoMakeup($trooperId, $weekStart);
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
            throw new RuntimeException('kpi read failed');
        }

        public function countingWindow(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek, bool $includeDeparted = false): CountingWindow
        {
            throw new RuntimeException('kpi read failed');
        }

        public function countingWindowAsPlaced(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek): CountingWindow
        {
            throw new RuntimeException('kpi read failed');
        }

        public function countingMarksOf(array $trooperIds, CarbonInterface $firstWeek, CarbonInterface $lastWeek, bool $includeDeparted = false): Collection
        {
            return $this->inner->countingMarksOf($trooperIds, $firstWeek, $lastWeek, $includeDeparted);
        }

        public function weekProgress(array $sectionIds, CarbonInterface $weekStart, array $excusedTrooperIds = [], ?array $roster = null): Collection
        {
            return $this->inner->weekProgress($sectionIds, $weekStart, $excusedTrooperIds, $roster);
        }
    });

    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'present']])
        ->assertOk()->assertJsonPath('atRisk', null);

    expect(Attendance::query()->count())->toBe(1);
});

it('returns the at-risk flags recomputed from the saved marks', function () {
    $prior = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week->subWeek()]);
    Attendance::factory()->create(['trooper_id' => $this->trooper->id, 'section_practice_id' => $prior->id, 'status' => AttendanceStatus::Missed]);

    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'missed']])
        ->assertOk()
        ->assertJsonPath('atRisk', [['trooperId' => $this->trooper->id, 'missed' => 2, 'windowWeeks' => 4]]);
});

it('requires authentication, writing nothing', function () {
    // A fetch asks for JSON, so a guest gets a 401 it can act on (the screen reloads into sign-in)
    // rather than a redirect to the login page it cannot follow.
    $this->postJson("/rollcall/{$this->section->id}", ['week' => '2026-10-03', 'marks' => []])->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);

    expect(SectionPractice::query()->count())->toBe(0);
});

it('refuses every rollcall write during a view-as preview, and the page sees the preview (1.13)', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id]);

    $this->actingAs($admin)->get("/rollcall/{$this->section->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->whereNot('viewAs', null));
    $this->actingAs($admin)->postJson("/rollcall/{$this->section->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => $this->trooper->id, 'status' => 'present']]])
        ->assertForbidden();
    $this->actingAs($admin)->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-03'])->assertForbidden();

    expect(SectionPractice::query()->count())->toBe(0);
});

// AD-12: once the section's weekly report for a week is sent, that week's rollcall is locked.

it('refuses any save for a week whose report has been sent, with a 409 the screen stops retrying, writing nothing', function (Closure $marks) {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]);

    saveRollcall($this->leader, $this->section->id, $marks($this->trooper))
        ->assertStatus(409)
        ->assertExactJson(['message' => 'The report for this week has been sent, so its roll call is locked.', 'locked' => true]);

    expect(SectionPractice::query()->count())->toBe(0)
        ->and(Attendance::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
})->with([
    'a practice mark' => [fn ($trooper) => [['trooper_id' => $trooper->id, 'status' => 'present']]],
    'a signature only' => [fn ($trooper) => [['trooper_id' => $trooper->id, 'rollcall' => 'will_attend']]],
    'an empty batch' => [fn () => []],
]);

it('keeps an unsent past week editable, with a draft report or with none', function (bool $draft) {
    if ($draft) {
        WeeklyReport::factory()->create(['unit_id' => $this->section->id, 'week_start' => $this->week->subWeeks(2)]);
    }

    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'present']], '2026-09-19')->assertOk();

    expect(SectionPractice::query()->sole()->week_start->toDateString())->toBe('2026-09-19');
})->with(['a draft report' => [true], 'no report' => [false]]);

it('keeps a week open whose only sent report is a generated demo report (#115)', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week, 'is_demo' => true]);

    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'present']])->assertOk();

    expect(Attendance::query()->count())->toBe(1);
});

it('locks only the sent unit-week: another section\'s sent report, or another week\'s, leaves this one open', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->sibling->id, 'week_start' => $this->week]);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week->subWeek()]);

    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'present']])->assertOk();

    expect(Attendance::query()->count())->toBe(1);
});

it('refuses marking a locked week\'s rollcall posted too', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-03'])
        ->assertStatus(409)->assertJsonPath('locked', true);
});
