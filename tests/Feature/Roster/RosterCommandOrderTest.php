<?php

use App\Authorization\ScopeResolver;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;

// #444: a unit is led by its commanding billet (the CO of a company, battalion or the regiment, the PL of a platoon,
// the SL of a section), never by the XO, 1SG, PSG, ASL, SGM or CSM whose billets map to it too. With the commanding
// billet vacant the unit's lead is vacant, and the rest of the command team keeps their tag and their scope.
// Fixture tree: 7CAV ─ 1-7 ─ A/1-7 ─ 1/A/1-7 ─ 1/1/A/1-7

/** A trooper placed on `$commands` whose billet `$title` commands it (the org import's mapping). */
function coHolder(Unit $commands, string $title, array $attributes = []): Trooper
{
    static $position = 8000;
    $position++;
    UnitCommandMapping::factory()->create(['unit_id' => $commands->id, 'cav_position_id' => $position]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $position, 'billet' => $title, ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $commands->id])->save();

    return $trooper;
}

/** @return array<int, ?int> the trooper id the roster names under each unit, with the whole regiment open */
function coLeaders($test): array
{
    $props = $test->actingAs($test->admin)->get("/roster/{$test->regiment->id}")->assertOk()->viewData('page')['props'];

    return collect($props['units'])->mapWithKeys(fn (array $unit) => [$unit['id'] => $unit['leader']['id'] ?? null])->all();
}

/** @return array<int, array<string, mixed>> the org page's units by id */
function coOrgUnits($test): array
{
    return collect($test->actingAs($test->admin)->get('/settings/org-structure')->viewData('page')['props']['units'])->keyBy('id')->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->admin = User::factory()->admin()->create();
});

it('names the commanding billet as each unit\'s leader, whoever synced first', function () {
    // The rest of each command team synced first (lower ids), so the old "last row wins" pick would name them.
    coHolder($this->regiment, 'Regimental Executive Officer');
    coHolder($this->regiment, 'Regimental Command Sergeant Major');
    coHolder($this->battalion, '1-7 Executive Officer');
    coHolder($this->battalion, '1-7 Sergeant Major');
    coHolder($this->company, 'Executive Officer A/1-7');
    coHolder($this->company, 'First Sergeant A/1-7');
    coHolder($this->platoon, 'Platoon Sergeant 1/A/1-7');
    coHolder($this->section, 'Assistant Section Leader 1/1/A/1-7');
    $rco = coHolder($this->regiment, 'Regimental Commanding Officer');
    $bnCo = coHolder($this->battalion, '1-7 Commanding Officer');
    $co = coHolder($this->company, 'Commander A/1-7');
    $pl = coHolder($this->platoon, 'Platoon Leader 1/A/1-7');
    $sl = coHolder($this->section, 'Section Leader 1/1/A/1-7');
    // Synced after the commander too: the order of sync never decides.
    coHolder($this->company, 'Executive Officer A/1-7');

    $expected = [
        $this->regiment->id => $rco->id,
        $this->battalion->id => $bnCo->id,
        $this->company->id => $co->id,
        $this->platoon->id => $pl->id,
        $this->section->id => $sl->id,
    ];
    $org = coOrgUnits($this);

    expect(app(UnitCommandMappingRepository::class)->commandersOf(array_keys($expected))->map(fn (Trooper $t) => $t->id)->all())->toEqual($expected)
        ->and(coLeaders($this))->toEqual($expected)
        ->and(collect($expected)->map(fn ($id, $unitId) => $org[$unitId]['lead']['displayName'] ?? null)->all())
        ->toEqual(collect($expected)->map(fn ($id) => Trooper::find($id)->display_name)->all());
});

it('shows the lead as vacant while the commanding billet is, and the XO or PSG keeps their tag and rights', function () {
    $xo = coHolder($this->company, 'Executive Officer A/1-7');
    $first = coHolder($this->company, 'First Sergeant A/1-7');
    $psg = coHolder($this->platoon, 'Platoon Sergeant 1/A/1-7');
    $asl = coHolder($this->section, 'Assistant Section Leader 1/1/A/1-7');

    $leaders = coLeaders($this);
    $org = coOrgUnits($this);

    expect($leaders[$this->company->id])->toBeNull()
        ->and($leaders[$this->platoon->id])->toBeNull()
        ->and($leaders[$this->section->id])->toBeNull()
        ->and($org[$this->company->id]['lead'])->toBeNull()
        ->and($org[$this->company->id]['lead_vacant'])->toBeTrue()
        ->and($org[$this->platoon->id]['lead_vacant'])->toBeTrue()
        ->and($org[$this->section->id]['lead_vacant'])->toBeTrue();

    $troopers = collect($this->actingAs($this->admin)->get("/roster/{$this->regiment->id}")->viewData('page')['props']['troopers'])->keyBy('id');
    expect($troopers[$xo->id]['role'])->toBe('XO')
        ->and($troopers[$first->id]['role'])->toBe('1SG')
        ->and($troopers[$psg->id]['role'])->toBe('PSG')
        ->and($troopers[$asl->id]['role'])->toBe('ASL');

    // Their scope comes from their own billet, not from leading the unit: unchanged.
    $scope = app(ScopeResolver::class);
    foreach ([[$xo, $this->company], [$psg, $this->platoon], [$asl, $this->section]] as [$trooper, $unit]) {
        $user = User::factory()->create(['cav_id' => $trooper->cav_id]);
        expect($scope->commandsUnit($user, $unit))->toBeTrue()
            ->and($scope->writeScope($user)->all())->toContain($unit->id);
    }
});

it('lets an acting lead lead a unit whose commanding billet is vacant, though its second in command is there', function () {
    $psg = coHolder($this->platoon, 'Platoon Sergeant 1/A/1-7');
    $asl = coHolder($this->section, 'Assistant Section Leader 1/1/A/1-7');
    $actingPl = Trooper::factory()->create(['billet' => 'Rifleman']);
    $actingPl->forceFill(['primary_section_id' => $this->platoon->id])->save();
    app(UnitActingLeadRepository::class)->assign($this->platoon->id, $actingPl->id, $this->admin->id);
    app(UnitActingLeadRepository::class)->assign($this->section->id, $asl->id, $this->admin->id);

    $leaders = coLeaders($this);
    $troopers = collect($this->actingAs($this->admin)->get("/roster/{$this->regiment->id}")->viewData('page')['props']['troopers'])->keyBy('id');

    expect($leaders[$this->platoon->id])->toBe($actingPl->id)
        ->and($leaders[$this->section->id])->toBe($asl->id)
        ->and($troopers[$actingPl->id]['role'])->toBe('Acting PL')
        ->and($troopers[$asl->id]['role'])->toBe('Acting SL')
        ->and($troopers[$psg->id]['role'])->toBe('PSG');
});
