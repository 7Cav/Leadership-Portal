<?php

use App\Commands\Units\CavDesignation;
use App\Commands\Units\CommandBillet;
use App\Models\UnitType;

// Story 1.16 — which Cav billets command a unit, and which unit. Pure: no app, no DB.

it('maps a line-company leadership billet to the unit it commands', function (string $title, UnitType $type, string $callsign) {
    $company = CavDesignation::companyGroup('A/1-7');
    $designation = CavDesignation::fromPositionTitle($title, $company);

    $commanded = CommandBillet::inLineGroup($title, $designation);

    expect($commanded->type)->toBe($type)
        ->and($commanded->callsign())->toBe($callsign);
})->with([
    ['Section Leader 2/1/A/1-7', UnitType::Section, '2/1/A/1-7'],
    ['Assistant Section Leader 2/1/A/1-7', UnitType::Section, '2/1/A/1-7'],
    ['Platoon Leader 1/A/1-7', UnitType::Platoon, '1/A/1-7'],
    ['Platoon Sergeant 1/A/1-7', UnitType::Platoon, '1/A/1-7'],
    ['Commander A/1-7', UnitType::Company, 'A/1-7'],
    ['Executive Officer A/1-7', UnitType::Company, 'A/1-7'],
    ['First Sergeant A/1-7', UnitType::Company, 'A/1-7'],
]);

it('confers no command for a non-leadership billet or a role at the wrong echelon', function (string $title) {
    $company = CavDesignation::companyGroup('A/1-7');

    expect(CommandBillet::inLineGroup($title, CavDesignation::fromPositionTitle($title, $company)))->toBeNull();
})->with([
    'Trooper 2/1/A/1-7',
    'Company Clerk A/1-7',
    // A role spelled at an echelon it does not command is not guessed into the nearest one.
    'Section Leader 1/A/1-7',
    'Platoon Leader 2/1/A/1-7',
    'Commander 1/A/1-7',
    'Platoon Leader A/1-7',
]);

it('maps regimental command billets to the regiment', function (string $title) {
    $commanded = CommandBillet::inCommandGroup('Regimental HQ', $title);

    expect($commanded->type)->toBe(UnitType::Regiment)
        ->and($commanded->key())->toBe(CavDesignation::regiment()->key());
})->with([
    'Regimental Commanding Officer',
    'Regimental Executive Officer',
    'Regimental Command Sergeant Major',
]);

it('maps a battalion command group\'s billets to that battalion', function (string $group, string $title, string $battalion) {
    $commanded = CommandBillet::inCommandGroup($group, $title);

    expect($commanded->type)->toBe(UnitType::Battalion)
        ->and($commanded->callsign())->toBe($battalion);
})->with([
    ['1-7 Command', '1-7 Commanding Officer', '1-7'],
    ['1-7 Command', '1-7 Executive Officer', '1-7'],
    ['1-7 Command', '1-7 Sergeant Major', '1-7'],
    ['2-7 Command', '2-7 Commanding Officer', '2-7'],
    ['ACD Command', 'Auxiliary Commander', 'ACD'],
    ['ACD Command', 'Auxiliary Executive Officer', 'ACD'],
    ['ACD Command', 'Auxiliary Sergeant Major', 'ACD'],
    ['ACD Command', 'ACD Commanding Officer', 'ACD'],
]);

it('confers no command for any other billet outside a line company', function (string $group, string $title) {
    expect(CommandBillet::inCommandGroup($group, $title))->toBeNull();
})->with([
    ['Support Attachment', 'S1 1IC'],
    ['1-7 Command', '1-7 Command Clerk'],
    // A battalion's command billet listed in another battalion's group names a different unit.
    ['1-7 Command', '2-7 Commanding Officer'],
    // The Auxiliary titles belong to ACD only.
    ['1-7 Command', 'Auxiliary Commander'],
    ['Retired', 'Retired'],
    // The regiment is the widest scope there is: its titles count only inside Regimental HQ.
    ['Retired', 'Regimental Commanding Officer'],
    ['1-7 Command', 'Regimental Executive Officer'],
    ['Regimental HQ', 'Regimental Clerk'],
    ['Start-Up Games', 'Section Leader Star Citizen Start-Up'],
    ['Discharged', 'Discharged'],
]);

it('maps DEVCOM 1IC and 2IC to the DEVCOM battalion in whichever group Cav lists them (#377)', function (string $group, string $title) {
    $designation = CommandBillet::inCommandGroup($group, $title);

    expect($designation?->type)->toBe(UnitType::Battalion)
        ->and($designation->callsign())->toBe('DEVCOM')
        ->and(CommandBillet::devcomCommand($title)?->key())->toBe($designation->key());
})->with([
    ['DEVCOM STAFF', 'DEVCOM 1IC'],
    ['DEVCOM STAFF', 'DEVCOM 2IC'],
    ['Development Command', ' DEVCOM 1IC '],
]);

it('maps no other DEVCOM billet, matching the titles exactly (#377)', function (string $title) {
    expect(CommandBillet::devcomCommand($title))->toBeNull()
        ->and(CommandBillet::inCommandGroup('DEVCOM STAFF', $title))->toBeNull();
})->with(['DEVCOM Lead', 'DEVCOM 3IC', 'DEVCOM Support Clerk', 'devcom 1ic', 'DEVCOM 1IC Aide']);
