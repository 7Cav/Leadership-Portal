<?php

use App\Commands\WeeklyReports\SetSectionTrainingSubjectHandler;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// #703: the roll call shows and sets the week's training topic, which is the weekly report's training subject (one
// value, not a copy): the platoon's topic prefills it and locks it when enforced.

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->week = '2026-10-03';
    $this->weekStart = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');

    $units = app(UnitRepository::class);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->leader = User::factory()->commanding($this->section)->create(['username' => 'Vasquez.R']);
    $this->platoonLeader = User::factory()->commanding($this->platoon)->create(['username' => 'Hale.J']);
    $this->trooper = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function rtrTopic(string $subject, bool $enforced, User $by): void
{
    app(PlatoonTrainingTopicRepository::class)->set(test()->platoon->id, test()->weekStart, $subject, $enforced, $by->id);
}

function rtrSet(User $actor, ?string $subject, string $week = '2026-10-03')
{
    return test()->actingAs($actor)->postJson('/rollcall/'.test()->section->id.'/training-topic', ['week' => $week, 'subject' => $subject]);
}

it('has no topic for a week nobody set one for', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('training.subject', null)
            ->where('training.enforced', false)
            ->where('training.platoon', null)
            ->where('training.canEdit', true));
});

it('prefills the platoon\'s topic, editable while it is not enforced, and locks it when it is', function () {
    rtrTopic('Room clearing', false, $this->platoonLeader);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('training.subject', 'Room clearing')
            ->where('training.enforced', false)
            ->where('training.platoon', '1st Platoon')
            ->where('training.canEdit', true));

    rtrTopic('Land navigation', true, $this->platoonLeader);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('training.subject', 'Land navigation')
            ->where('training.enforced', true)
            ->where('training.canEdit', false));
});

it('saves the topic as the weekly report\'s training subject, keeping the draft\'s notes and reasons', function () {
    $reasons = [['trooper_id' => $this->trooper->id, 'reason' => 'no_notice']];
    app(WeeklyReportRepository::class)->saveDraft($this->section->id, $this->weekStart, [], 'Good week.', 'Old subject', $reasons);

    rtrSet($this->leader, '  Land navigation  ')->assertOk()->assertJsonPath('training.subject', 'Land navigation');

    $report = WeeklyReport::query()->sole();
    expect($report->training_subject)->toBe('Land navigation')
        ->and($report->notes)->toBe('Good week.')
        ->and($report->unaccounted)->toEqual($reasons)
        ->and(AuditLogEntry::query()->where('action', SetSectionTrainingSubjectHandler::ACTION)->count())->toBe(1);

    // The same value on the report page: one subject, not a copy.
    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('report.trainingSubject', 'Land navigation'));
});

it('shows a subject saved on the weekly report as the roll call\'s topic', function () {
    $this->actingAs($this->leader)->putJson("/reports/{$this->section->id}/{$this->week}", ['notes' => null, 'training_subject' => 'Radio procedure', 'unaccounted' => []]);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('training.subject', 'Radio procedure'));
});

it('clears the section\'s own topic with an empty one, falling back to the platoon\'s', function () {
    rtrTopic('Room clearing', false, $this->platoonLeader);
    rtrSet($this->leader, 'Our own')->assertOk();

    rtrSet($this->leader, '')->assertOk()->assertJsonPath('training.subject', 'Room clearing');

    expect(WeeklyReport::query()->sole()->training_subject)->toBeNull();
});

it('refuses a different topic while the platoon enforces one, and writes nothing', function () {
    rtrTopic('Land navigation', true, $this->platoonLeader);

    rtrSet($this->leader, 'Something else')->assertStatus(422);

    expect(WeeklyReport::query()->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->where('action', SetSectionTrainingSubjectHandler::ACTION)->exists())->toBeFalse();
});

it('refuses a week whose report was sent, as locked (AD-12)', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week, 'training_subject' => 'Frozen']);

    rtrSet($this->leader, 'Changed')->assertStatus(409)->assertJson(['locked' => true]);

    expect(WeeklyReport::query()->sole()->training_subject)->toBe('Frozen');
});

it('refuses a section outside the leader\'s write scope, a future week, and a subject that is too long', function () {
    rtrSet(User::factory()->serving()->create(), 'Anything')->assertForbidden();
    rtrSet($this->leader, 'Later', '2026-10-10')->assertStatus(422);
    rtrSet($this->leader, str_repeat('x', 256))->assertStatus(422);

    expect(WeeklyReport::query()->exists())->toBeFalse();
});
