<?php

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// 3.8 / AD-8, AD-14, AD-16, AD-22: the rollcall screen's autosave writes through the audited
// MarkAttendance Command and answers JSON.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '910/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '910/911/']);
    $this->sibling = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '910/912/']);
    $this->leader = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $this->section->id]);
    $this->trooper = Trooper::factory()->create();
    $this->trooper->forceFill(['primary_section_id' => $this->section->id])->save();
});

function saveRollcall(User $user, int $unitId, array $marks, string $week = '2026-10-03')
{
    return test()->actingAs($user)->postJson("/rollcall/{$unitId}", ['week' => $week, 'marks' => $marks]);
}

it('writes a practice mark and a signature as one manual, audited row', function () {
    saveRollcall($this->leader, $this->section->id, [
        ['trooper_id' => $this->trooper->id, 'status' => 'attached', 'rollcall' => 'will_attend'],
    ])->assertOk()->assertJsonStructure(['savedAt', 'atRisk']);

    $row = Attendance::query()->sole();
    expect($row->status)->toBe(AttendanceStatus::Attached)
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
    'unknown status' => [['status' => 'excused']],
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

it('404s a section that does not exist', function () {
    saveRollcall($this->leader, 999999, [])->assertNotFound();
});

it('returns the at-risk flags recomputed from the saved marks', function () {
    $prior = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week->subWeek()]);
    Attendance::factory()->create(['trooper_id' => $this->trooper->id, 'section_practice_id' => $prior->id, 'status' => AttendanceStatus::Missed]);

    saveRollcall($this->leader, $this->section->id, [['trooper_id' => $this->trooper->id, 'status' => 'missed']])
        ->assertOk()
        ->assertJsonPath('atRisk', [['trooperId' => $this->trooper->id, 'missed' => 2, 'windowWeeks' => 4]]);
});

it('requires authentication, writing nothing', function () {
    // The app renders JSON errors for api/* only, so a guest is redirected — the screen treats any
    // answer without a `savedAt` as a failed save.
    $this->postJson("/rollcall/{$this->section->id}", ['week' => '2026-10-03', 'marks' => []])->assertRedirect(route('login'));

    expect(SectionPractice::query()->count())->toBe(0);
});
