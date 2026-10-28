<?php

use App\Models\AuditLogEntry;
use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// Settings, Org structure with staff, ELOA and New Recruit placement (decided 2026-10-24): counts include HQ staff,
// New Recruits count only on their own group, a lead billet nobody holds shows vacant, a group lists its members (ELOA
// with where they were), an Admin labels a unit and places a trooper on an HQ node or into a group.

function ospPlace(Unit $unit, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create(['status' => TrooperStatus::Combat, ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $unit->id])->save();

    return $trooper;
}

/** @return array<int, array<string, mixed>> the page's units by id */
function ospUnits($response): array
{
    return collect($response->viewData('page')['props']['units'])->keyBy('id')->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->eloa = $units->create(UnitType::Group, 'Extended Leave of Absence', 'ELOA', $this->regiment->id, UnitKind::Eloa);
    $this->recruits = $units->create(UnitType::Group, 'New Recruits', 'NR', $this->regiment->id, UnitKind::Recruit);

    ospPlace($this->section);
    ospPlace($this->section);
    $this->psg = ospPlace($this->platoon, ['username' => 'Hollis.P', 'billet' => 'Platoon Sergeant 1/A/1-7', 'cav_position_id' => 711]);
    $this->onLeave = ospPlace($this->eloa, ['username' => 'Marlowe.E', 'billet' => 'ELOA', 'status' => TrooperStatus::Eloa]);
    $this->onLeave->forceFill(['last_line_unit_id' => $this->section->id])->save();
    ospPlace($this->recruits, ['username' => 'Quill.R', 'billet' => 'New Recruit']);

    // The Platoon Leader billet nobody holds; the company's command billet held by nobody either; the PSG holds his.
    UnitCommandMapping::factory()->create(['unit_id' => $this->platoon->id, 'cav_position_id' => 710]);
    UnitCommandMapping::factory()->create(['unit_id' => $this->platoon->id, 'cav_position_id' => 711]);
    UnitCommandMapping::factory()->create(['unit_id' => $this->company->id, 'cav_position_id' => 720]);

    $this->admin = User::factory()->admin()->create();
});

it('counts HQ staff in their unit and every ancestor, and New Recruits only on their own group', function () {
    $units = ospUnits($this->actingAs($this->admin)->get('/settings/org-structure')->assertOk());

    expect($units[$this->section->id]['trooper_count'])->toBe(2)
        ->and($units[$this->platoon->id]['trooper_count'])->toBe(3)
        ->and($units[$this->platoon->id]['own_count'])->toBe(1)
        ->and($units[$this->company->id]['trooper_count'])->toBe(3)
        ->and($units[$this->eloa->id]['trooper_count'])->toBe(1)
        ->and($units[$this->recruits->id]['trooper_count'])->toBe(1)
        // Regiment: the line and ELOA, not the New Recruit.
        ->and($units[$this->regiment->id]['trooper_count'])->toBe(4)
        ->and($units[$this->recruits->id]['kind'])->toBe('recruit');
});

it('shows a held lead by name and an unheld one as vacant, with no previous holder', function () {
    ospPlace($this->company, ['username' => 'Cole.D', 'billet' => 'Commander A/1-7', 'cav_position_id' => 720]);
    $units = ospUnits($this->actingAs($this->admin)->get('/settings/org-structure'));

    expect($units[$this->company->id]['lead']['displayName'])->toContain('Cole.D')
        ->and($units[$this->company->id]['lead_vacant'])->toBeFalse()
        // The Platoon Leader billet is vacant: the PSG who holds his own billet does not lead in its place (#444).
        ->and($units[$this->platoon->id]['lead'])->toBeNull()
        ->and($units[$this->platoon->id]['lead_vacant'])->toBeTrue()
        // No billet commands the section: no lead to be vacant.
        ->and($units[$this->section->id]['lead_vacant'])->toBeFalse();
});

it('lists each group\'s members with an ELOA trooper\'s "was", to viewers whose scope holds the group', function () {
    $this->actingAs($this->admin)->get('/settings/org-structure')
        ->assertInertia(fn ($page) => $page
            ->where("groupMembers.{$this->eloa->id}.0.displayName", fn ($name) => str_contains($name, 'Marlowe.E'))
            ->where("groupMembers.{$this->eloa->id}.0.was", '1/1/A/1-7')
            ->where("groupMembers.{$this->recruits->id}.0.was", null));

    $outsider = User::factory()->serving()->create();
    $this->actingAs($outsider)->get('/settings/org-structure')
        ->assertInertia(fn ($page) => $page->where('groupMembers', []));
});

it('lets an Admin set and clear a unit\'s game label, audited, and refuses anyone else', function () {
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->platoon->id}/label", ['label' => ' WARDOGS '])
        ->assertRedirect('/settings/org-structure')
        ->assertSessionHasNoErrors();

    expect($this->platoon->fresh()->label)->toBe('WARDOGS')
        ->and(AuditLogEntry::where('action', 'unit.labelled')->count())->toBe(1);

    // Unchanged: nothing written, nothing logged.
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->platoon->id}/label", ['label' => 'WARDOGS']);
    expect(AuditLogEntry::where('action', 'unit.labelled')->count())->toBe(1);

    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->platoon->id}/label", ['label' => '']);
    expect($this->platoon->fresh()->label)->toBeNull()
        ->and(AuditLogEntry::where('action', 'unit.labelled')->count())->toBe(2);

    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->platoon->id}/label", ['label' => str_repeat('x', 41)])
        ->assertSessionHasErrors('label');

    $this->actingAs(User::factory()->serving()->create())->patch("/settings/org-structure/{$this->platoon->id}/label", ['label' => 'X'])
        ->assertForbidden();
});

it('lets an Admin place an unplaced trooper on an HQ node or into a group, with a reason', function () {
    $staff = Trooper::factory()->create(['primary_section_id' => null, 'status' => TrooperStatus::Combat]);
    $reservist = Trooper::factory()->create(['primary_section_id' => null, 'status' => TrooperStatus::Reserve]);

    $this->actingAs($this->admin)->post("/settings/org-structure/troopers/{$staff->id}/placement", ['section_id' => $this->company->id, 'reason' => 'Company clerk, billet not mapped yet.'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("/settings/org-structure/troopers/{$reservist->id}/placement", ['section_id' => $this->eloa->id, 'reason' => 'Confirmed by S1.'])
        ->assertSessionHasNoErrors();

    expect($staff->fresh()->primary_section_id)->toBe($this->company->id)
        ->and($staff->fresh()->placement_source)->toBe(PlacementSource::Manual)
        ->and($reservist->fresh()->primary_section_id)->toBe($this->eloa->id)
        ->and(AuditLogEntry::where('action', 'trooper.placed')->count())->toBe(2);

    // The reason is still required.
    $other = Trooper::factory()->create(['primary_section_id' => null, 'status' => TrooperStatus::Combat]);
    $this->actingAs($this->admin)->post("/settings/org-structure/troopers/{$other->id}/placement", ['section_id' => $this->platoon->id, 'reason' => ''])
        ->assertSessionHasErrors('reason');
    expect($other->fresh()->primary_section_id)->toBeNull();
});

it('lets an Admin move a group to the regiment or a staff group, never a line unit under a group', function () {
    $units = app(UnitRepository::class);
    $portfolio = $units->create(UnitType::Group, 'Security Operations', 'SECOPS', $this->regiment->id, UnitKind::Staff);
    $department = $units->create(UnitType::Group, 'MP', 'MP', $this->regiment->id, UnitKind::Staff);

    $this->actingAs($this->admin)->patch("/settings/org-structure/{$department->id}", ['name' => 'MP', 'callsign' => 'MP', 'parent_id' => $portfolio->id])
        ->assertSessionHasNoErrors();
    expect($department->fresh()->parent_id)->toBe($portfolio->id)
        ->and(AuditLogEntry::where('action', 'unit.moved')->count())->toBe(1);

    $this->actingAs($this->admin)->patch("/settings/org-structure/{$department->id}", ['name' => 'MP', 'callsign' => 'MP', 'parent_id' => $this->eloa->id])
        ->assertSessionHasErrors('parent_id');
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->section->id}", ['name' => '1st Section', 'callsign' => '1/1/A/1-7', 'parent_id' => $portfolio->id])
        ->assertSessionHasErrors('parent_id');
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$department->id}", ['name' => 'MP', 'callsign' => 'MP', 'parent_id' => $this->platoon->id])
        ->assertSessionHasErrors('parent_id');
});

it('counts HQ staff in roster strength, keeps them unmeasured, and lists ELOA troopers with their "was"', function () {
    $this->actingAs($this->admin)->get("/roster/{$this->platoon->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('units.0.kpis.strength', 3)
            ->where('troopers', fn ($rows) => collect($rows)->firstWhere('id', $this->psg->id)['kpis'] === null));

    $this->actingAs($this->admin)->get("/roster/{$this->regiment->id}")
        ->assertInertia(fn ($page) => $page
            // The line and ELOA; the New Recruit only on their own group.
            ->where('units.0.kpis.strength', 4)
            ->where('troopers', fn ($rows) => collect($rows)->firstWhere('id', $this->onLeave->id)['was'] === '1/1/A/1-7'));
});
