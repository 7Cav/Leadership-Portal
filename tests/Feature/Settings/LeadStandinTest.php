<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitActingLead;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// A unit whose lead billet is empty shows who stands in (display only): the next serving holder of its command team by
// seniority, an Admin's acting lead overriding it, "HQ vacant" when nobody serves.

/** Map billet `$position` to `$unit` and, unless `$status` is null, give it to a trooper. */
function lsBillet(Unit $unit, int $position, string $billet, string $username, ?TrooperStatus $status = TrooperStatus::Combat): ?Trooper
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $position]);

    return $status === null ? null : Trooper::factory()->create([
        'status' => $status, 'username' => $username, 'billet' => $billet, 'cav_position_id' => $position,
    ]);
}

/** @return array<int, array<string, mixed>> */
function lsUnits($test): array
{
    return collect($test->actingAs(User::factory()->admin()->create())->get('/settings/org-structure')->assertOk()
        ->viewData('page')['props']['units'])->keyBy('id')->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
});

it('shows no vacancy while the lead billet is held', function () {
    lsBillet($this->company, 10, 'Commander A/1-7', 'Cole.D');
    lsBillet($this->company, 11, 'Executive Officer A/1-7', 'Ames.X');

    $unit = lsUnits($this)[$this->company->id];

    expect($unit['lead_vacant'])->toBeFalse()
        ->and($unit['lead_standin'])->toBeNull()
        ->and($unit['lead_vacant_label'])->toBeNull();
});

it('has the XO stand in for an empty battalion CO billet, the SGM when only he serves, else HQ vacant', function () {
    lsBillet($this->battalion, 20, '1-7 Commanding Officer', 'Gone.C', null);
    lsBillet($this->battalion, 22, '1-7 Sergeant Major', 'Stone.S');
    $xo = lsBillet($this->battalion, 21, '1-7 Executive Officer', 'Ames.X');

    $unit = lsUnits($this)[$this->battalion->id];
    expect($unit['lead_vacant'])->toBeTrue()
        ->and($unit['lead_standin']['billetShort'])->toBe('XO')
        ->and($unit['lead_standin']['displayName'])->toBe($xo->display_name)
        ->and($unit['lead_standin']['actingFor'])->toBe('CO')
        ->and($unit['lead_vacant_label'])->toBe('CO vacant');

    $xo->forceFill(['status' => TrooperStatus::Inactive])->save();
    $unit = lsUnits($this)[$this->battalion->id];
    expect($unit['lead_standin']['billetShort'])->toBe('SGM')
        ->and($unit['lead_vacant_label'])->toBe('CO vacant');

    Trooper::query()->update(['status' => TrooperStatus::Inactive]);
    $unit = lsUnits($this)[$this->battalion->id];
    expect($unit['lead_standin'])->toBeNull()
        ->and($unit['lead_vacant_label'])->toBe('HQ vacant');
});

it('says the lead and oversight vacancy together on a battalion with neither', function () {
    lsBillet($this->battalion, 20, '1-7 Commanding Officer', 'Gone.C', null);
    $xo = lsBillet($this->battalion, 21, '1-7 Executive Officer', 'Ames.X');

    $units = lsUnits($this);
    expect($units[$this->battalion->id]['oversight_vacant'])->toBeTrue()
        ->and($units[$this->battalion->id]['vacancy_label'])->toBe('Oversight & CO vacant')
        ->and($units[$this->company->id]['vacancy_label'])->toBeNull();

    $xo->forceFill(['status' => TrooperStatus::Inactive])->save();
    expect(lsUnits($this)[$this->battalion->id]['vacancy_label'])->toBe('Oversight & HQ vacant');
});

it('takes the company XO before the First Sergeant, and the 1SG when the XO billet is empty', function () {
    lsBillet($this->company, 10, 'Commander A/1-7', 'Gone.C', null);
    lsBillet($this->company, 12, 'First Sergeant A/1-7', 'Stone.S');
    lsBillet($this->company, 11, 'Executive Officer A/1-7', 'Ames.X');

    expect(lsUnits($this)[$this->company->id]['lead_standin']['billetShort'])->toBe('XO');

    Trooper::query()->where('username', 'Ames.X')->delete();
    expect(lsUnits($this)[$this->company->id]['lead_vacant_label'])->toBe('CO vacant');
});

it('has the PSG stand in for a platoon and the ASL for a section, skipping one not serving', function () {
    lsBillet($this->platoon, 30, 'Platoon Sergeant 1/A/1-7', 'Hollis.P');
    lsBillet($this->section, 40, 'Assistant Section Leader 1/1/A/1-7', 'Gone.A', TrooperStatus::Inactive);

    $units = lsUnits($this);

    expect($units[$this->platoon->id]['lead_vacant_label'])->toBe('PL vacant')
        ->and($units[$this->section->id]['lead_vacant'])->toBeTrue()
        ->and($units[$this->section->id]['lead_standin'])->toBeNull()
        ->and($units[$this->section->id]['lead_vacant_label'])->toBe('HQ vacant');
});

it('lets an Admin-assigned acting lead override the stand-in', function () {
    lsBillet($this->platoon, 30, 'Platoon Sergeant 1/A/1-7', 'Hollis.P');
    $acting = Trooper::factory()->create(['status' => TrooperStatus::Combat, 'username' => 'Taylor.C']);
    UnitActingLead::query()->create(['unit_id' => $this->platoon->id, 'trooper_id' => $acting->id]);

    $unit = lsUnits($this)[$this->platoon->id];

    expect($unit['lead']['source'])->toBe('acting')
        ->and($unit['lead_vacant'])->toBeFalse()
        ->and($unit['lead_standin'])->toBeNull()
        ->and($unit['lead_vacant_label'])->toBeNull();
});
