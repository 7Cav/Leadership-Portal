<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitActingLead;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Rollcall\ChainOfCommand;
use App\Support\Time\WeekOf;
use Inertia\Testing\AssertableInertia as Assert;

// The chain a template prints as `{{coc}}`: the section's command billets and those of each unit above it up to its
// battalion, bottom-up, each holder with their full rank, milpac name and milpac link.
//
//   7th Cavalry Regiment (RCO)
//     1-7 (SGM, XO, CO)          ACD (Auxiliary SGM, XO, Commander)        DEVCOM (1IC, 2IC)
//       A/1-7 (1SG, CO)            A/ACD                                     D/DEVCOM
//         1/A/1-7 (PSG, PL)          1/A/ACD (acting PL)                       1/D/DEVCOM
//           1/1/A/1-7 (ASL, SL)        1/1/A/ACD (SL)                            1/1/D/DEVCOM

function cocHolder(int $cavId, string $username, string $rank, string $billet, Unit $unit, TrooperStatus $status = TrooperStatus::Combat): Trooper
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => 7000 + $cavId]);

    return Trooper::factory()->create([
        'cav_id' => $cavId, 'cav_position_id' => 7000 + $cavId, 'billet' => $billet, 'status' => $status,
        'rank' => $rank, 'username' => $username, 'primary_section_id' => $unit->id,
    ]);
}

/** @return list<string> */
function cocLines(Unit $section): array
{
    return array_map(function (array $line) {
        $who = $line['holders'] === []
            ? 'Vacant'
            : implode(', ', array_map(fn (array $holder) => "{$holder['rank']} {$holder['name']} <{$holder['url']}>", $line['holders']));

        return "{$line['role']}: {$who}".($line['acting'] ? ' (acting)' : '');
    }, app(ChainOfCommand::class)->lines($section));
}

function cocMilpac(int $cavId): string
{
    return (new Trooper(['cav_id' => $cavId]))->milpacUrl();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
});

it('lists every billet from the section up to its battalion, bottom-up, and nothing regimental', function () {
    cocHolder(1, 'Shakif.A', 'Specialist', 'Assistant Section Leader 1/1/A/1-7', $this->section);
    cocHolder(2, 'Vasquez.R', 'Sergeant', 'Section Leader 1/1/A/1-7', $this->section);
    cocHolder(3, 'Randolph.R', 'Staff Sergeant', 'Platoon Sergeant 1/A/1-7', $this->platoon);
    cocHolder(4, 'Laui.M', 'First Lieutenant', 'Platoon Leader 1/A/1-7', $this->platoon);
    cocHolder(5, 'Stover.C', 'First Sergeant', 'First Sergeant A/1-7', $this->company);
    cocHolder(6, 'Reyes.M', 'First Lieutenant', 'Executive Officer A/1-7', $this->company);
    cocHolder(7, 'Adams.P', 'Captain', 'Commander A/1-7', $this->company);
    cocHolder(8, 'Preacher.A', 'Sergeant Major', '1-7 Sergeant Major', $this->battalion);
    cocHolder(9, 'Korfias.F', 'Major', '1-7 Executive Officer', $this->battalion);
    cocHolder(10, 'Dallas.E', 'Lieutenant Colonel', '1-7 Commanding Officer', $this->battalion);
    cocHolder(11, 'Hightower.R', 'Colonel', 'Regimental Commanding Officer', $this->regiment);

    expect(cocLines($this->section))->toBe([
        'Assistant Section Leader: Specialist Shakif.A <'.cocMilpac(1).'>',
        'Section Leader: Sergeant Vasquez.R <'.cocMilpac(2).'>',
        'Platoon Sergeant: Staff Sergeant Randolph.R <'.cocMilpac(3).'>',
        'Platoon Leader: First Lieutenant Laui.M <'.cocMilpac(4).'>',
        'Company First Sergeant: First Sergeant Stover.C <'.cocMilpac(5).'>',
        'Company Executive Officer: First Lieutenant Reyes.M <'.cocMilpac(6).'>',
        'Company Commanding Officer: Captain Adams.P <'.cocMilpac(7).'>',
        'Battalion Sergeant Major: Sergeant Major Preacher.A <'.cocMilpac(8).'>',
        'Battalion Executive Officer: Major Korfias.F <'.cocMilpac(9).'>',
        'Battalion Commanding Officer: Lieutenant Colonel Dallas.E <'.cocMilpac(10).'>',
    ]);
});

it('shows a billet nobody serving holds as vacant', function () {
    cocHolder(2, 'Vasquez.R', 'Sergeant', 'Section Leader 1/1/A/1-7', $this->section);
    // A departed trooper's last billet holds nothing.
    cocHolder(7, 'Adams.P', 'Captain', 'Commander A/1-7', $this->company, TrooperStatus::Inactive);

    expect(cocLines($this->section))->toBe([
        'Assistant Section Leader: Vacant',
        'Section Leader: Sergeant Vasquez.R <'.cocMilpac(2).'>',
        'Platoon Sergeant: Vacant',
        'Platoon Leader: Vacant',
        'Company First Sergeant: Vacant',
        'Company Executive Officer: Vacant',
        'Company Commanding Officer: Vacant',
        'Battalion Sergeant Major: Vacant',
        'Battalion Executive Officer: Vacant',
        'Battalion Commanding Officer: Vacant',
    ]);
});

it('leaves out an echelon the section has no unit at', function () {
    // A section straight under a company: there is no platoon to list.
    $section = app(UnitRepository::class)->create(UnitType::Section, 'HQ Section', 'HQ/A/1-7', $this->company->id);
    cocHolder(7, 'Adams.P', 'Captain', 'Commander A/1-7', $this->company);

    expect(cocLines($section))->toBe([
        'Assistant Section Leader: Vacant',
        'Section Leader: Vacant',
        'Company First Sergeant: Vacant',
        'Company Executive Officer: Vacant',
        'Company Commanding Officer: Captain Adams.P <'.cocMilpac(7).'>',
        'Battalion Sergeant Major: Vacant',
        'Battalion Executive Officer: Vacant',
        'Battalion Commanding Officer: Vacant',
    ]);
});

it('reads the ACD command team as a battalion and marks an acting lead', function () {
    $units = app(UnitRepository::class);
    $acd = $units->create(UnitType::Battalion, 'Auxiliary Combat Detachment', 'ACD', $this->regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/ACD', $acd->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/ACD', $company->id);
    $section = $units->create(UnitType::Section, '1st Section', '1/1/A/ACD', $platoon->id);

    cocHolder(21, 'Ortiz.L', 'Sergeant', 'Section Leader 1/1/A/ACD', $section);
    cocHolder(22, 'Ward.K', 'Sergeant Major', 'Auxiliary Sergeant Major', $acd);
    cocHolder(23, 'Lee.J', 'Major', 'Auxiliary Executive Officer', $acd);
    cocHolder(24, 'Moss.T', 'Lieutenant Colonel', 'Auxiliary Commander', $acd);
    $acting = Trooper::factory()->create(['cav_id' => 25, 'username' => 'Novak.T', 'rank' => 'Staff Sergeant', 'status' => TrooperStatus::Combat, 'billet' => 'Rifleman']);
    UnitActingLead::query()->create(['unit_id' => $platoon->id, 'trooper_id' => $acting->id, 'assigned_by' => null]);

    expect(cocLines($section))->toBe([
        'Assistant Section Leader: Vacant',
        'Section Leader: Sergeant Ortiz.L <'.cocMilpac(21).'>',
        'Platoon Sergeant: Vacant',
        'Platoon Leader: Staff Sergeant Novak.T <'.cocMilpac(25).'> (acting)',
        'Company First Sergeant: Vacant',
        'Company Executive Officer: Vacant',
        'Company Commanding Officer: Vacant',
        'Battalion Sergeant Major: Sergeant Major Ward.K <'.cocMilpac(22).'>',
        'Battalion Executive Officer: Major Lee.J <'.cocMilpac(23).'>',
        'Battalion Commanding Officer: Lieutenant Colonel Moss.T <'.cocMilpac(24).'>',
    ]);
});

it('reads the DEVCOM 1IC and 2IC as its commander and executive officer, with no sergeant major line', function () {
    $units = app(UnitRepository::class);
    $devcom = $units->create(UnitType::Battalion, 'Development Command', 'DEVCOM', $this->regiment->id);
    $company = $units->create(UnitType::Company, 'Delta Company', 'D/DEVCOM', $devcom->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/D/DEVCOM', $company->id);
    $section = $units->create(UnitType::Section, '1st Section', '1/1/D/DEVCOM', $platoon->id);

    cocHolder(31, 'Grayson.J', 'Captain', 'DEVCOM 1IC', $devcom);
    cocHolder(32, 'Hale.D', 'First Lieutenant', 'DEVCOM 2IC', $devcom);
    // The 1IC's billet is the one the battalion is led by (#377).
    $units->setLeadPosition($devcom, 7031);

    expect(cocLines($section))->toBe([
        'Assistant Section Leader: Vacant',
        'Section Leader: Vacant',
        'Platoon Sergeant: Vacant',
        'Platoon Leader: Vacant',
        'Company First Sergeant: Vacant',
        'Company Executive Officer: Vacant',
        'Company Commanding Officer: Vacant',
        'Battalion Executive Officer: First Lieutenant Hale.D <'.cocMilpac(32).'>',
        'Battalion Commanding Officer: Captain Grayson.J <'.cocMilpac(31).'>',
    ]);
});

it('sends the chain to the rollcall page as chainOfCommand', function () {
    cocHolder(2, 'Vasquez.R', 'Sergeant', 'Section Leader 1/1/A/1-7', $this->section);
    $admin = User::factory()->admin()->create();

    // An explicit week: on a Saturday the bare URL redirects to the closing week while its report is due.
    $this->actingAs($admin)->get("/rollcall/{$this->section->id}?week=".WeekOf::start(now())->toDateString())->assertInertia(fn (Assert $page) => $page
        ->has('chainOfCommand', 10)
        ->where('chainOfCommand.1', ['role' => 'Section Leader', 'holders' => [['rank' => 'Sergeant', 'name' => 'Vasquez.R', 'url' => cocMilpac(2)]], 'acting' => false]));
});
