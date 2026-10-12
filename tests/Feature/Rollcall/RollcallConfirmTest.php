<?php

use App\Commands\Attendance\MarkAttendanceHandler;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// 7.8 / AD-14, AD-16, AD-20, AD-22: the leader reviews auto marks, overrides one, and confirms.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'path' => '940/']);
    $this->leader = User::factory()->create(['username' => 'Vasquez.R']);
    PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $this->section->id]);
    $this->ortega = Trooper::factory()->create(['username' => 'Ortega.M']);
    $this->dube = Trooper::factory()->create(['username' => 'Dube.R']);
    foreach ([$this->ortega, $this->dube] as $trooper) {
        $trooper->forceFill(['primary_section_id' => $this->section->id])->save();
    }
    $this->practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-10-03 00:00:00']);
    $this->practice->forceFill(['captured_at' => '2026-10-08 12:00:00', 'capture_source' => 'crcon', 'server_ref' => 'crcon-1'])->save();
    app(MarkAttendanceHandler::class)->preMark($this->practice, [$this->ortega->id, $this->dube->id]);
});

function markByHand(object $test, int $trooperId, string $status)
{
    return $test->actingAs($test->leader)->postJson("/rollcall/{$test->section->id}", [
        'week' => '2026-10-03', 'marks' => [['trooper_id' => $trooperId, 'status' => $status]],
    ]);
}

it('shows each row its source and the capture stamp, so auto marks are reviewable', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")->assertInertia(fn (Assert $page) => $page
        ->where('capture', ['source' => 'crcon', 'capturedAt' => '2026-10-08T12:00:00+00:00'])
        ->where('troopers.0.source', 'auto')->where('troopers.1.source', 'auto')
        ->where('practice.confirmation', null));
});

it('writes an override as source=manual through the audited writer, and leaves untouched rows auto', function () {
    markByHand($this, $this->dube->id, 'missed')->assertOk();

    $rows = Attendance::query()->where('section_practice_id', $this->practice->id)->get()->keyBy('trooper_id');
    expect($rows[$this->dube->id]->status)->toBe(AttendanceStatus::Missed)
        ->and($rows[$this->dube->id]->source)->toBe(AttendanceSource::Manual)
        ->and($rows[$this->ortega->id]->source)->toBe(AttendanceSource::Auto)
        ->and(AuditLogEntry::query()->where('action', 'attendance.marked')->count())->toBe(1);
});

it('keeps an override across a capture re-run, which still re-stamps the practice (manual wins)', function () {
    markByHand($this, $this->dube->id, 'missed')->assertOk();

    $result = app(MarkAttendanceHandler::class)->preMark($this->practice, [$this->ortega->id, $this->dube->id]);

    expect($result->marked)->toBe(1)->and($result->keptManual)->toBe(1)
        ->and(Attendance::query()->where('trooper_id', $this->dube->id)->sole()->status)->toBe(AttendanceStatus::Missed);
});

it('confirms the practice as an audited, scope-gated action and shows it on the page', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/confirm", ['week' => '2026-10-03'])
        ->assertOk()->assertJsonPath('confirmation', ['at' => '2026-10-08T16:20:00+00:00', 'by' => 'Vasquez.R']);

    $entry = AuditLogEntry::query()->where('action', 'practice.confirmed')->sole();
    expect($entry->actor_id)->toBe($this->leader->id)->and((int) $entry->target_id)->toBe($this->practice->id);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn (Assert $page) => $page->where('practice.confirmation', ['at' => '2026-10-08T16:20:00+00:00', 'by' => 'Vasquez.R']));
});

it('confirms a purely manual practice the same way', function () {
    $other = Unit::factory()->create(['type' => UnitType::Section, 'path' => '941/']);
    PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $other->id]);

    $this->actingAs($this->leader)->postJson("/rollcall/{$other->id}/confirm", ['week' => '2026-10-03'])->assertOk();

    expect(SectionPractice::query()->where('section_id', $other->id)->sole()->confirmed_at)->not->toBeNull();
});

it('refuses outside scope, a future week, a missing week and a non-section', function () {
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->postJson("/rollcall/{$this->section->id}/confirm", ['week' => '2026-10-03'])->assertForbidden();
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/confirm", ['week' => '2026-10-10'])->assertStatus(422);
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/confirm", [])->assertStatus(422);

    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '942/']);
    $this->actingAs(User::factory()->admin()->create())->postJson("/rollcall/{$platoon->id}/confirm", ['week' => '2026-10-03'])->assertStatus(422);

    expect($this->practice->fresh()->confirmed_at)->toBeNull();
});

it('clears the confirmation when a practice mark is changed by hand, but not for a signature', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/confirm", ['week' => '2026-10-03'])->assertOk();

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}", [
        'week' => '2026-10-03', 'marks' => [['trooper_id' => $this->dube->id, 'rollcall' => 'will_attend']],
    ])->assertOk();
    expect($this->practice->fresh()->confirmed_at)->not->toBeNull();

    markByHand($this, $this->dube->id, 'missed')->assertOk();
    expect($this->practice->fresh()->confirmed_at)->toBeNull()->and($this->practice->fresh()->confirmed_by)->toBeNull();
});

it('locks a confirmed practice against capture pre-marks until a hand edit', function () {
    $extra = Trooper::factory()->create();
    $extra->forceFill(['primary_section_id' => $this->section->id])->save();
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/confirm", ['week' => '2026-10-03'])->assertOk();

    $result = app(MarkAttendanceHandler::class)->preMark($this->practice, [$extra->id]);

    expect($result->marked)->toBe(0)
        ->and(Attendance::query()->where('trooper_id', $extra->id)->exists())->toBeFalse();
});
