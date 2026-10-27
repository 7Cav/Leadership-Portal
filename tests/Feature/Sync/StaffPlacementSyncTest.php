<?php

use App\Authorization\ScopeResolver;
use App\Integration\Cav\CavApi;
use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitCommandMapping;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Sync\SyncRosterHandler;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// Staff, ELOA, Reservist and New Recruit placement by the roster sync (decided 2026-10-24): a billet mapping of any unit
// first, then the command mapping, and the ELOA "was" kept from the line unit a trooper left.

function spRow(string $positionId, string $title, string $roster = 'ROSTER_TYPE_COMBAT', string $username = 'Jarvis.A'): array
{
    return [
        'user' => ['userId' => '3', 'username' => $username],
        'rank' => ['rankShort' => 'SGT', 'rankFull' => 'Sergeant', 'rankImageUrl' => '', 'rankId' => '4'],
        'realName' => 'Real Name',
        'uniformUrl' => '',
        'roster' => $roster,
        'primary' => ['positionTitle' => $title, 'positionId' => $positionId],
        'secondaries' => [],
        'joinDate' => '2020-01-01',
        'promotionDate' => '2022-01-01',
        'discordId' => '',
        'lastForumPostDate' => '',
        'mos' => '11B',
        'consoleGamertag' => '',
    ];
}

/** @param array<string, array> $combat cav id => row */
function spSync(array $combat = [], array $reserve = [], array $eloa = []): void
{
    MockClient::destroyGlobal();
    MockClient::global([
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => MockResponse::make(['profiles' => $combat]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_RESERVE/lite' => MockResponse::make(['profiles' => $reserve]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_ELOA/lite' => MockResponse::make(['profiles' => $eloa]),
    ]);
    app(SyncRosterHandler::class)->handle();
}

function spPlacedIn(int $cavId): ?int
{
    return Trooper::where('cav_id', $cavId)->sole()->primary_section_id;
}

beforeEach(function () {
    app()->instance(CavApi::class, new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']));
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->devcom = $units->create(UnitType::Battalion, 'DEVCOM', 'DEVCOM', $this->regiment->id);
    $this->devcomCompany = $units->create(UnitType::Company, 'Delta Company', 'D/DEVCOM', $this->devcom->id);
    $this->devcomPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/D/DEVCOM', $this->devcomCompany->id);
    $this->devcomSection = $units->create(UnitType::Section, '1st Section', '1/1/D/DEVCOM', $this->devcomPlatoon->id);
    $this->eloa = $units->create(UnitType::Group, 'Extended Leave of Absence', 'ELOA', $this->regiment->id, UnitKind::Eloa);
    $this->reserve = $units->create(UnitType::Group, 'Reservists', 'RES', $this->regiment->id, UnitKind::Reserve);
    $this->recruits = $units->create(UnitType::Group, 'New Recruits', 'NR', $this->regiment->id, UnitKind::Recruit);
    $this->s6 = $units->create(UnitType::Group, 'S6', 'S6', $this->regiment->id, UnitKind::Staff);
    $this->devcomStaff = $units->create(UnitType::Group, 'DEVCOM Staff', 'DEVCOM STAFF', $this->regiment->id, UnitKind::Staff);

    foreach ([700 => $this->section, 900 => $this->eloa, 901 => $this->reserve, 902 => $this->recruits, 903 => $this->s6, 904 => $this->regiment, 905 => $this->devcomStaff] as $position => $unit) {
        UnitBilletMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $position]);
    }
    foreach ([710 => $this->platoon, 711 => $this->company, 712 => $this->battalion, 713 => $this->regiment] as $position => $unit) {
        UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $position]);
    }
});

it('places every kind of billet: section, HQ staff on their HQ node, departments and the holding groups', function () {
    spSync(
        combat: [
            '1' => spRow('700', 'Trooper 1/1/A/1-7'),
            '2' => spRow('710', 'Platoon Leader 1/A/1-7'),
            '3' => spRow('711', 'Commander A/1-7'),
            '4' => spRow('712', '1-7 Commanding Officer'),
            '5' => spRow('713', 'Regimental Commanding Officer'),
            '6' => spRow('904', 'Regimental Chief of Staff'),
            '7' => spRow('903', 'S6 1IC'),
            '8' => spRow('902', 'New Recruit'),
            '9' => spRow('905', 'DEVCOM Lead'),
        ],
        reserve: ['10' => spRow('901', 'Reservist', 'ROSTER_TYPE_RESERVE')],
        eloa: ['11' => spRow('900', 'ELOA', 'ROSTER_TYPE_ELOA')],
    );

    expect(spPlacedIn(1))->toBe($this->section->id)
        ->and(spPlacedIn(2))->toBe($this->platoon->id)
        ->and(spPlacedIn(3))->toBe($this->company->id)
        ->and(spPlacedIn(4))->toBe($this->battalion->id)
        ->and(spPlacedIn(5))->toBe($this->regiment->id)
        ->and(spPlacedIn(6))->toBe($this->regiment->id)
        ->and(spPlacedIn(7))->toBe($this->s6->id)
        ->and(spPlacedIn(8))->toBe($this->recruits->id)
        // A DEVCOM Lead is rostered to DEVCOM staff.
        ->and(spPlacedIn(9))->toBe($this->devcomStaff->id)
        ->and(spPlacedIn(10))->toBe($this->reserve->id)
        ->and(spPlacedIn(11))->toBe($this->eloa->id)
        // First seen on ELOA: nothing to say where they were.
        ->and(Trooper::where('cav_id', 11)->sole()->last_line_unit_id)->toBeNull()
        ->and(Trooper::where('cav_id', 2)->sole()->placement_source)->toBe(PlacementSource::Sync);
});

it('moves a placed trooper who goes on ELOA out of their section, keeps it as their "was", and clears it on return', function () {
    spSync(combat: ['1' => spRow('700', 'Trooper 1/1/A/1-7')]);
    expect(spPlacedIn(1))->toBe($this->section->id);

    spSync(eloa: ['1' => spRow('900', 'ELOA', 'ROSTER_TYPE_ELOA')]);
    $onLeave = Trooper::where('cav_id', 1)->sole();
    expect($onLeave->primary_section_id)->toBe($this->eloa->id)
        ->and($onLeave->last_line_unit_id)->toBe($this->section->id)
        ->and($onLeave->status)->toBe(TrooperStatus::Eloa);

    // A second sync while still on ELOA keeps the "was".
    spSync(eloa: ['1' => spRow('900', 'ELOA', 'ROSTER_TYPE_ELOA')]);
    expect(Trooper::where('cav_id', 1)->sole()->last_line_unit_id)->toBe($this->section->id);

    spSync(combat: ['1' => spRow('700', 'Trooper 1/1/A/1-7')]);
    $back = Trooper::where('cav_id', 1)->sole();
    expect($back->primary_section_id)->toBe($this->section->id)
        ->and($back->last_line_unit_id)->toBeNull();
});

it('keeps an HQ node as the "was" of a Platoon Leader who goes on ELOA', function () {
    spSync(combat: ['2' => spRow('710', 'Platoon Leader 1/A/1-7')]);
    spSync(eloa: ['2' => spRow('900', 'ELOA', 'ROSTER_TYPE_ELOA')]);

    expect(Trooper::where('cav_id', 2)->sole()->last_line_unit_id)->toBe($this->platoon->id);
});

it('records no "was" for a trooper moving into ELOA from a group', function () {
    spSync(reserve: ['3' => spRow('901', 'Reservist', 'ROSTER_TYPE_RESERVE')]);
    spSync(eloa: ['3' => spRow('900', 'ELOA', 'ROSTER_TYPE_ELOA')]);

    expect(Trooper::where('cav_id', 3)->sole()->last_line_unit_id)->toBeNull();
});

it('gives a DEVCOM Lead no command by billet, only what an Admin assigns as acting lead', function () {
    spSync(combat: ['9' => spRow('905', 'DEVCOM Lead', username: 'Taylor.C'), '2' => spRow('710', 'Platoon Leader 1/A/1-7', username: 'Brennan.J')]);
    $lead = User::factory()->create(['cav_id' => 9]);
    $platoonLeader = User::factory()->create(['cav_id' => 2]);
    $scope = app(ScopeResolver::class);

    expect($scope->writeScope($lead)->all())->toBe([])
        ->and($scope->writeScope($platoonLeader)->all())->toContain($this->section->id, $this->platoon->id)
        // Leaders see their HQ staff: the PL sits on the platoon, inside the company commander's scope.
        ->and($scope->canSee(User::factory()->create(['cav_id' => 99]), Trooper::where('cav_id', 2)->sole()))->toBeFalse();

    spSync(combat: ['3' => spRow('711', 'Commander A/1-7', username: 'Cole.D'), '2' => spRow('710', 'Platoon Leader 1/A/1-7')]);
    $commander = User::factory()->create(['cav_id' => 3]);
    expect($scope->canSee($commander, Trooper::where('cav_id', 2)->sole()))->toBeTrue();
});

it('leaves a trooper whose billet maps nowhere where they were', function () {
    $manual = Unit::query()->whereKey($this->section->id)->sole();
    Trooper::factory()->create(['cav_id' => 20])->forceFill(['primary_section_id' => $manual->id, 'placement_source' => PlacementSource::Manual])->save();

    spSync(combat: ['20' => spRow('9999', 'Something Unmapped')]);

    expect(spPlacedIn(20))->toBe($manual->id)
        ->and(Trooper::where('cav_id', 20)->sole()->placement_source)->toBe(PlacementSource::Manual);
});
