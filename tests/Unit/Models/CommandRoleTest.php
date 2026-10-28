<?php

use App\Models\CommandRole;
use App\Models\UnitType;

// #443: the short name of the command billet a title names in the unit its billet commands. Pure: no app, no DB.

it('names each echelon\'s command billets by their short name', function (string $title, UnitType $type, string $short) {
    expect(CommandRole::fromBillet($title, $type)?->value)->toBe($short);
})->with([
    ['Commander A/1-7', UnitType::Company, 'CO'],
    ['Executive Officer A/1-7', UnitType::Company, 'XO'],
    ['First Sergeant A/1-7', UnitType::Company, '1SG'],
    ['Platoon Leader 1/A/1-7', UnitType::Platoon, 'PL'],
    ['Platoon Sergeant 1/A/1-7', UnitType::Platoon, 'PSG'],
    ['Section Leader 2/1/A/1-7', UnitType::Section, 'SL'],
    ['Assistant Section Leader 2/1/A/1-7', UnitType::Section, 'ASL'],
    ['1-7 Commanding Officer', UnitType::Battalion, 'CO'],
    ['1-7 Executive Officer', UnitType::Battalion, 'XO'],
    ['1-7 Sergeant Major', UnitType::Battalion, 'SGM'],
    ['Auxiliary Commander', UnitType::Battalion, 'CO'],
    ['Auxiliary Executive Officer', UnitType::Battalion, 'XO'],
    ['Auxiliary Sergeant Major', UnitType::Battalion, 'SGM'],
    ['Regimental Commanding Officer', UnitType::Regiment, 'CO'],
    ['Regimental Executive Officer', UnitType::Regiment, 'XO'],
    ['Regimental Command Sergeant Major', UnitType::Regiment, 'CSM'],
]);

it('names nothing for a title that is not one of the echelon\'s command billets', function (?string $title, UnitType $type) {
    expect(CommandRole::fromBillet($title, $type))->toBeNull();
})->with([
    ['Rifleman', UnitType::Section],
    ['Training Leader', UnitType::Section],
    ['Platoon Leader 1/A/1-7', UnitType::Section],
    ['Section Leader 2/1/A/1-7', UnitType::Platoon],
    ['Company Clerk A/1-7', UnitType::Company],
    ['Regimental Security Operations Officer', UnitType::Group],
    ['Regimental Chief of Staff', UnitType::Regiment],
    [null, UnitType::Company],
]);

it('names the billet that leads each echelon, and none for a group', function () {
    expect(CommandRole::leadOf(UnitType::Regiment))->toBe(CommandRole::Co)
        ->and(CommandRole::leadOf(UnitType::Battalion))->toBe(CommandRole::Co)
        ->and(CommandRole::leadOf(UnitType::Company))->toBe(CommandRole::Co)
        ->and(CommandRole::leadOf(UnitType::Platoon))->toBe(CommandRole::Pl)
        ->and(CommandRole::leadOf(UnitType::Section))->toBe(CommandRole::Sl)
        ->and(CommandRole::leadOf(UnitType::Group))->toBeNull();
});
