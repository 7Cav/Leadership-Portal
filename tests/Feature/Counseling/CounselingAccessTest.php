<?php

use App\Authorization\CounselingAccess;
use App\Authorization\RealActor;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitActingLead;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// #381: who may file, see and change a DCS or an LOR (decided by the product owner 2026-10-28).
// Fixture tree: 7CAV ─┬─ 1-7 ─ A/1-7 ─ 1/A/1-7 ─┬─ 1/1/A/1-7 (SL Sl.One, ASL Asl.One)
//                     │                          └─ 2/1/A/1-7 (SL Sl.Two)
//                     └─ S6 (staff department, portfolio officer Imo.Officer)

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->section2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);
    $this->s6 = $units->create(UnitType::Group, 'S6', 'S6', $this->regiment->id, UnitKind::Staff);

    $placed = fn (Unit $at) => ['primary_section_id' => $at->id];
    $this->users = [
        'admin' => User::factory()->admin()->create(),
        'sl1' => User::factory()->commanding($this->section1, $placed($this->section1))->create(),
        'asl1' => User::factory()->commanding($this->section1, $placed($this->section1))->create(),
        'sl2' => User::factory()->commanding($this->section2, $placed($this->section2))->create(),
        'pl' => User::factory()->commanding($this->platoon, $placed($this->platoon))->create(),
        'psg' => User::factory()->commanding($this->platoon, $placed($this->platoon))->create(),
        'co' => User::factory()->commanding($this->company, $placed($this->company))->create(),
        'imo' => User::factory()->commanding($this->s6, $placed($this->regiment))->create(),
        'grant' => User::factory()->serving()->create(),
        'nobody' => User::factory()->serving()->create(),
    ];
    PortalRoleGrant::factory()->create(['user_id' => $this->users['grant']->id, 'unit_id' => $this->company->id]);

    $this->rifleman = Trooper::factory()->create(['primary_section_id' => $this->section1->id]);
    $this->staffer = Trooper::factory()->create(['primary_section_id' => $this->s6->id]);
    $this->trooperOf = fn (string $user) => Trooper::query()->where('cav_id', $this->users[$user]->cav_id)->sole();
});

function counselingFor(User $user, Trooper $trooper)
{
    return app(CounselingAccess::class)->for($user, $trooper);
}

it('lets every leader whose command reaches the trooper file a DCS, and only platoon command and up an LOR', function (string $who, bool $dcs, bool $lor) {
    $permissions = counselingFor($this->users[$who], $this->rifleman);

    expect($permissions->filesDcs)->toBe($dcs, "{$who} DCS")
        ->and($permissions->filesLor)->toBe($lor, "{$who} LOR");
})->with([
    'admin' => ['admin', true, true],
    'own section leader' => ['sl1', true, false],
    'own assistant section leader' => ['asl1', true, false],
    'another section\'s leader' => ['sl2', false, false],
    'platoon leader' => ['pl', true, true],
    'platoon sergeant' => ['psg', true, true],
    'company command' => ['co', true, true],
    'staff grant on the company' => ['grant', false, false],
    'no command' => ['nobody', false, false],
    'a portfolio officer outside the line' => ['imo', false, false],
]);

it('lets a portfolio officer file a DCS on their department\'s staff, but not an LOR', function () {
    $permissions = counselingFor($this->users['imo'], $this->staffer);

    expect($permissions->filesDcs)->toBeTrue()
        ->and($permissions->filesLor)->toBeFalse()
        ->and($permissions->seesDcs)->toBeTrue()
        ->and($permissions->issuingUnit(CounselingType::Dcs))->toBe($this->s6->id);
});

it('counts an acting lead as command of that unit: a platoon\'s files an LOR, a section\'s only a DCS', function () {
    $platoonLead = User::factory()->serving()->create();
    UnitActingLead::query()->forceCreate(['unit_id' => $this->platoon->id, 'trooper_id' => Trooper::query()->where('cav_id', $platoonLead->cav_id)->sole()->id]);
    $sectionLead = User::factory()->serving()->create();
    UnitActingLead::query()->forceCreate(['unit_id' => $this->section1->id, 'trooper_id' => Trooper::query()->where('cav_id', $sectionLead->cav_id)->sole()->id]);

    expect(counselingFor($platoonLead, $this->rifleman)->filesLor)->toBeTrue()
        ->and(counselingFor($sectionLead, $this->rifleman)->filesDcs)->toBeTrue()
        ->and(counselingFor($sectionLead, $this->rifleman)->filesLor)->toBeFalse();
});

it('never lets a leader file on someone who holds the same command, or on themselves', function () {
    $sl = ($this->trooperOf)('sl1');
    $pl = ($this->trooperOf)('pl');

    // The ASL shares the section with their Section Leader; the Platoon Sergeant shares the platoon with the PL.
    expect(counselingFor($this->users['asl1'], $sl)->filesDcs)->toBeFalse()
        ->and(counselingFor($this->users['psg'], $pl)->filesDcs)->toBeFalse()
        ->and(counselingFor($this->users['psg'], $pl)->filesLor)->toBeFalse()
        // The echelon above does.
        ->and(counselingFor($this->users['pl'], $sl)->filesLor)->toBeTrue()
        ->and(counselingFor($this->users['co'], $pl)->filesLor)->toBeTrue()
        // Nobody files on themselves, an Admin included.
        ->and(counselingFor($this->users['sl1'], $sl)->filesDcs)->toBeFalse()
        ->and(counselingFor($this->users['co'], ($this->trooperOf)('co'))->filesLor)->toBeFalse();

    $admin = $this->users['admin'];
    $admin->forceFill(['cav_id' => $this->rifleman->cav_id])->save();
    expect(counselingFor($admin, $this->rifleman)->filesDcs)->toBeFalse();
});

it('shows a DCS to platoon command and up and the trooper\'s own section leaders, and an LOR to everyone who sees the trooper', function (string $who, bool $dcs, bool $lor) {
    $permissions = counselingFor($this->users[$who], $this->rifleman);

    expect($permissions->seesDcs)->toBe($dcs, "{$who} DCS")
        ->and($permissions->seesLor)->toBe($lor, "{$who} LOR");
})->with([
    'admin' => ['admin', true, true],
    'own section leader' => ['sl1', true, true],
    'own assistant section leader' => ['asl1', true, true],
    'another section\'s leader' => ['sl2', false, false],
    'platoon leader' => ['pl', true, true],
    'company command' => ['co', true, true],
    'staff grant on the company' => ['grant', false, true],
    'no command' => ['nobody', false, false],
]);

it('hides a Section Leader\'s DCSs from their own ASL and from themselves, but not their LORs', function () {
    $sl = ($this->trooperOf)('sl1');

    expect(counselingFor($this->users['asl1'], $sl)->seesDcs)->toBeFalse()
        ->and(counselingFor($this->users['sl1'], $sl)->seesDcs)->toBeFalse()
        ->and(counselingFor($this->users['sl1'], $sl)->seesLor)->toBeTrue()
        ->and(counselingFor($this->users['pl'], $sl)->seesDcs)->toBeTrue();
});

it('lets only the issuer\'s chain of command or an Admin change a record', function () {
    $bySection = CounselingRecord::factory()->create(['trooper_id' => $this->rifleman->id, 'issuer_unit_id' => $this->section1->id]);
    $byPlatoon = CounselingRecord::factory()->lor()->create(['trooper_id' => $this->rifleman->id, 'issuer_unit_id' => $this->platoon->id]);
    $byAdmin = CounselingRecord::factory()->create(['trooper_id' => $this->rifleman->id, 'issuer_unit_id' => null]);

    $changes = fn (string $who, CounselingRecord $record) => counselingFor($this->users[$who], $this->rifleman)->changes($record);

    expect($changes('sl1', $bySection))->toBeTrue()
        ->and($changes('asl1', $bySection))->toBeTrue()
        ->and($changes('pl', $bySection))->toBeTrue()
        ->and($changes('co', $bySection))->toBeTrue()
        ->and($changes('sl2', $bySection))->toBeFalse()
        ->and($changes('grant', $bySection))->toBeFalse()
        // A section leader sees an LOR filed by their platoon but is not in its chain.
        ->and($changes('sl1', $byPlatoon))->toBeFalse()
        ->and($changes('pl', $byPlatoon))->toBeTrue()
        ->and($changes('co', $byPlatoon))->toBeTrue()
        ->and($changes('admin', $byPlatoon))->toBeTrue()
        // With no issuing unit only an Admin may.
        ->and($changes('co', $byAdmin))->toBeFalse()
        ->and($changes('admin', $byAdmin))->toBeTrue();
});

it('files through the lowest command that reaches the trooper', function () {
    // The PL also leads 1st Section by an acting lead: their lowest command over the rifleman is the section.
    UnitActingLead::query()->forceCreate(['unit_id' => $this->section1->id, 'trooper_id' => ($this->trooperOf)('pl')->id]);
    $permissions = counselingFor($this->users['pl'], $this->rifleman);

    expect($permissions->issuingUnit(CounselingType::Dcs))->toBe($this->section1->id)
        ->and($permissions->issuingUnit(CounselingType::Lor))->toBe($this->platoon->id)
        ->and(counselingFor($this->users['admin'], $this->rifleman)->issuingUnit(CounselingType::Dcs))->toBeNull();
});

it('gives nothing on a trooper the sync has not placed, except to an Admin', function () {
    $unplaced = Trooper::factory()->create(['primary_section_id' => null]);

    expect(counselingFor($this->users['co'], $unplaced)->filesDcs)->toBeFalse()
        ->and(counselingFor($this->users['co'], $unplaced)->seesLor)->toBeFalse()
        ->and(counselingFor($this->users['admin'], $unplaced)->filesLor)->toBeTrue()
        ->and(counselingFor($this->users['admin'], $unplaced)->seesDcs)->toBeTrue();
});

it('gives nobody inside a trooper\'s own command a say over their counseling', function () {
    // A rifleman of 1st Section made acting Platoon Leader of their own platoon.
    UnitActingLead::query()->forceCreate(['unit_id' => $this->platoon->id, 'trooper_id' => $this->rifleman->id]);
    $bySection = CounselingRecord::factory()->create(['trooper_id' => $this->rifleman->id, 'issuer_unit_id' => $this->section1->id]);
    $byPlatoon = CounselingRecord::factory()->lor()->create(['trooper_id' => $this->rifleman->id, 'issuer_unit_id' => $this->platoon->id]);

    foreach (['sl1', 'asl1', 'pl', 'psg'] as $below) {
        $permissions = counselingFor($this->users[$below], $this->rifleman);

        expect($permissions->filesDcs)->toBeFalse("{$below} files a DCS")
            ->and($permissions->filesLor)->toBeFalse("{$below} files an LOR")
            ->and($permissions->seesDcs)->toBeFalse("{$below} sees a DCS")
            ->and($permissions->changes($bySection))->toBeFalse("{$below} changes the DCS")
            ->and($permissions->changes($byPlatoon))->toBeFalse("{$below} changes the LOR");
    }

    $co = counselingFor($this->users['co'], $this->rifleman);
    expect($co->filesLor)->toBeTrue()
        ->and($co->seesDcs)->toBeTrue()
        ->and($co->issuingUnit(CounselingType::Lor))->toBe($this->company->id)
        ->and($co->changes($bySection))->toBeTrue();
});

it('counts a billet the same way: a Platoon Leader placed in a section is not counseled by its Section Leader', function () {
    $pl = ($this->trooperOf)('pl');
    $pl->forceFill(['primary_section_id' => $this->section1->id])->save();

    expect(counselingFor($this->users['sl1'], $pl)->filesDcs)->toBeFalse()
        ->and(counselingFor($this->users['sl1'], $pl)->seesDcs)->toBeFalse()
        ->and(counselingFor($this->users['co'], $pl)->filesLor)->toBeTrue();
});

it('treats the person really signed in as themselves while they act as someone else', function () {
    $sl = ($this->trooperOf)('sl1');
    $record = CounselingRecord::factory()->create(['trooper_id' => $sl->id, 'issuer_unit_id' => $this->platoon->id]);
    // The Section Leader is acting as their Platoon Leader (whatever let them): the request runs as the PL.
    app(RealActor::class)->set($this->users['sl1']);

    $permissions = counselingFor($this->users['pl'], $sl);
    expect($permissions->self)->toBeTrue()
        ->and($permissions->filesDcs)->toBeFalse()
        ->and($permissions->filesLor)->toBeFalse()
        ->and($permissions->seesDcs)->toBeFalse()
        ->and($permissions->changes($record))->toBeFalse()
        // Everyone else is judged as the PL.
        ->and(counselingFor($this->users['pl'], $this->rifleman)->filesLor)->toBeTrue();

    app(RealActor::class)->clear();
    expect(counselingFor($this->users['pl'], $sl)->filesLor)->toBeTrue();
});
