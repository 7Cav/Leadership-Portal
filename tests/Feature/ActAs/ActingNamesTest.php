<?php

use App\Models\CrossSectionSighting;
use App\Models\PlatoonTrainingTopic;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\RollcallGuests;
use App\Support\Audit\ActingFor;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// #379: what an Admin does while acting as someone keeps the person's name on the record and names the Admin beside
// them, so a report reads "sent by Hale.S for SGT Mercer.J". Done in one's own name, nothing changes.

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->week = '2026-10-03';

    $units = app(UnitRepository::class);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);

    $this->platoonLeader = anCommander($this->platoon->id, 901, 'Hale.J', 'SFC');
    $this->sectionLeader = anCommander($this->section->id, 902, 'Mercer.J', 'SGT');
    Trooper::factory()->create(['primary_section_id' => $this->section->id, 'status' => TrooperStatus::Combat]);

    $this->admin = User::factory()->admin()->create(['username' => 'Hale.S']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function anCommander(int $unitId, int $positionId, string $username, string $rank): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unitId, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId, 'username' => $username, 'rank_short' => $rank, 'status' => TrooperStatus::Combat]);

    return User::factory()->create(['cav_id' => $trooper->cav_id, 'username' => $username]);
}

function anActAs(object $test, User $user): void
{
    $test->actingAs($test->admin)->post('/act-as', ['user' => $user->id])->assertSessionHasNoErrors();
}

it('shows a report sent while acting as sent by the Admin for the leader', function () {
    anActAs($this, $this->sectionLeader);

    $this->actingAs($this->admin)->post("/reports/{$this->section->id}/{$this->week}/send", ['reason' => 'Weekly report', 'notes' => 'Leader on leave.'])
        ->assertSessionHasNoErrors();

    $report = WeeklyReport::query()->sole();
    expect($report->sent_by)->toBe($this->sectionLeader->id)
        ->and($report->sent_by_admin)->toBe($this->admin->id);

    $this->actingAs($this->admin)->get("/reports/{$this->section->id}/{$this->week}")
        ->assertInertia(fn (Assert $page) => $page->where('report.sentBy', 'Hale.S for SGT Mercer.J'));

    $this->actingAs($this->admin)->delete('/act-as');
    $this->actingAs($this->admin)->get("/reports/{$this->platoon->id}")
        ->assertInertia(fn (Assert $page) => $page->where('roots.0.children.0.sentBy', 'Hale.S for Mercer.J'));
});

it('shows a topic set while acting as set by the Admin for the platoon leader', function () {
    anActAs($this, $this->platoonLeader);

    $this->actingAs($this->admin)->put("/reports/{$this->platoon->id}/{$this->week}/topic", ['subject' => 'Room clearing', 'enforced' => true])
        ->assertSessionHasNoErrors();

    expect(PlatoonTrainingTopic::query()->sole())->set_by->toBe($this->platoonLeader->id)->set_by_admin->toBe($this->admin->id);

    $this->actingAs($this->admin)->get("/reports/{$this->section->id}/{$this->week}")
        ->assertInertia(fn (Assert $page) => $page->where('topic.setBy', 'Hale.S for SFC Hale.J'));

    // Set again by the leader themselves, it is theirs alone.
    $this->actingAs($this->admin)->delete('/act-as');
    $this->actingAs($this->platoonLeader)->put("/reports/{$this->platoon->id}/{$this->week}/topic", ['subject' => 'Bounding', 'enforced' => true]);

    expect(PlatoonTrainingTopic::query()->sole()->set_by_admin)->toBeNull();
});

it('shows a guest added while acting as added by the Admin for the leader', function () {
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->section->id, CarbonImmutable::parse($this->week, 'UTC'));
    $guest = Trooper::factory()->create(['status' => TrooperStatus::Combat]);
    $other = Trooper::factory()->create(['status' => TrooperStatus::Combat]);

    app(CrossSectionSightingRepository::class)->recordManual($practice, $guest->id, $this->sectionLeader->id, $this->admin->id);
    app(CrossSectionSightingRepository::class)->recordManual($practice, $other->id, $this->sectionLeader->id);

    $rows = collect(app(RollcallGuests::class)->forPractice($practice))->keyBy('trooperId');

    expect(CrossSectionSighting::where('trooper_id', $guest->id)->sole()->recorded_by_admin)->toBe($this->admin->id)
        ->and($rows[$guest->id]['recordedBy'])->toBe('Hale.S for Mercer.J')
        ->and($rows[$other->id]['recordedBy'])->toBe('Mercer.J');
});

it('names the Admin only for the person they act as, never for anyone else', function () {
    $acting = new ActingFor;
    expect($acting->adminActingAs(5))->toBeNull();

    $acting->set(1, 5);
    expect($acting->adminActingAs(5))->toBe(1)
        ->and($acting->adminActingAs(1))->toBeNull()
        ->and($acting->adminActingAs(9))->toBeNull();
});
