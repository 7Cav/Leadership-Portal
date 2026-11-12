<?php

use App\Commands\Attendance\CreateRollcallHandler;
use App\Models\AuditLogEntry;
use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// #703: a week on the new flow starts with "Create roll call": the training date (this week's practice, from the slot)
// and the topic (the weekly report's training subject, from the platoon). The date changes this week only.

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 16:20:00', 'UTC'));
    config(['portal.rollcall_flow_v2_from' => '2026-11-21']);
    $this->week = '2026-11-21';

    $units = app(UnitRepository::class);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->leader = User::factory()->commanding($this->section)->create(['username' => 'Practice.L']);
    $this->platoonLeader = User::factory()->commanding($this->platoon)->create();
    Trooper::factory()->create(['primary_section_id' => $this->section->id]);

    // Wednesdays 2300Z: this week's practice is Wed 25NOV26 2300Z.
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/slot", ['weekday' => 3, 'start_minute' => 1380, 'week' => $this->week])->assertOk();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function rcCreate(array $body = [], ?User $actor = null)
{
    return test()->actingAs($actor ?? test()->leader)->postJson('/rollcall/'.test()->section->id.'/create', ['week' => test()->week, ...$body]);
}

it('shows a new-flow week as not created yet, with the date from the slot', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week={$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flow', ['version' => 2, 'created' => null])
            ->where('practiceSlot.week.start', '2026-11-25T23:00:00Z'));
});

it('creates the roll call as one audited action, leaving the slot\'s date as it is', function () {
    rcCreate(['moved_to' => '2026-11-25T23:00:00Z'])
        ->assertOk()
        ->assertJsonPath('flow.version', 2)
        ->assertJsonPath('flow.created', ['at' => '2026-11-25T16:20:00+00:00', 'by' => 'Practice.L']);

    $practice = SectionPractice::query()->sole();
    expect($practice->rollcall_created_at)->not->toBeNull()
        ->and($practice->slot_moved_to)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', CreateRollcallHandler::ACTION)->count())->toBe(1)
        ->and(AuditLogEntry::query()->where('action', 'practice_week.changed')->exists())->toBeFalse();

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week={$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('flow.created.by', 'Practice.L'));
});

it('moves this week\'s practice only when the date was changed, and the slot stays', function () {
    rcCreate(['moved_to' => '2026-11-26T20:00:00Z'])->assertOk()->assertJsonPath('practiceSlot.week.start', '2026-11-26T20:00:00Z');

    expect(SectionPractice::query()->sole()->slot_moved_to->toIso8601ZuluString())->toBe('2026-11-26T20:00:00Z')
        ->and(SectionPracticeSlot::query()->sole())->weekday->toBe(3)->start_minute->toBe(1380)
        ->and(AuditLogEntry::query()->where('action', 'practice_week.changed')->count())->toBe(1);
});

it('refuses a date outside the week and writes nothing', function () {
    rcCreate(['moved_to' => '2026-11-28T20:00:00Z'])->assertStatus(422);

    expect(SectionPractice::query()->whereNotNull('rollcall_created_at')->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->where('action', CreateRollcallHandler::ACTION)->exists())->toBeFalse();
});

it('cancels this week\'s practice when it will not take place', function () {
    rcCreate(['cancelled' => true])->assertOk()->assertJsonPath('practiceSlot.week.cancelled', true);

    expect(SectionPractice::query()->sole())->slot_cancelled->toBeTrue()->rollcall_created_at->not->toBeNull();
});

it('saves the topic as the weekly report\'s training subject', function () {
    rcCreate(['subject' => 'Land navigation'])->assertOk()->assertJsonPath('training.subject', 'Land navigation');

    expect(WeeklyReport::query()->sole()->training_subject)->toBe('Land navigation');
});

it('keeps a topic the platoon enforces, and refuses a different one with nothing written', function () {
    app(PlatoonTrainingTopicRepository::class)->set($this->platoon->id, CarbonImmutable::parse($this->week, 'UTC'), 'Land navigation', true, $this->platoonLeader->id);

    rcCreate(['subject' => 'Something else'])->assertStatus(422);
    expect(SectionPractice::query()->whereNotNull('rollcall_created_at')->exists())->toBeFalse();

    rcCreate(['subject' => null])->assertOk()->assertJsonPath('training.subject', 'Land navigation')->assertJsonPath('training.enforced', true);
});

it('refuses a week on the three-step flow, which has no create step', function () {
    SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week, 'flow_version' => 1]);

    rcCreate()->assertStatus(422)->assertJsonPath('message', 'This week follows the three-step roll call, which is not created first.');

    $this->week = '2026-11-14';
    rcCreate()->assertStatus(422);
});

it('refuses a sent week as locked, a future week, and a section outside the leader\'s scope', function () {
    rcCreate([], User::factory()->serving()->create())->assertForbidden();

    $this->week = '2026-11-28';
    rcCreate()->assertStatus(422);

    $this->week = '2026-11-21';
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]);
    rcCreate()->assertStatus(409)->assertJson(['locked' => true]);

    expect(SectionPractice::query()->whereNotNull('rollcall_created_at')->exists())->toBeFalse();
});
