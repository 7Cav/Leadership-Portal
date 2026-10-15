<?php

use App\Commands\Units\CavDesignation;
use App\Models\UnitType;

// Story 2.9 — the designation rules the org-structure import builds every node from. Pure: no
// app, no DB.

function cdCompany(string $group = 'A/1-7'): CavDesignation
{
    return CavDesignation::companyGroup($group);
}

it('recognises a line-company group and names its battalion and company', function (string $group, string $battalionCallsign, string $battalionName, string $companyName) {
    $company = CavDesignation::companyGroup($group);

    expect($company->type)->toBe(UnitType::Company)
        ->and($company->callsign())->toBe($group)
        ->and($company->name())->toBe($companyName)
        ->and($company->parent()->type)->toBe(UnitType::Battalion)
        ->and($company->parent()->callsign())->toBe($battalionCallsign)
        ->and($company->parent()->name())->toBe($battalionName)
        ->and($company->parent()->parent()->callsign())->toBe('7CAV')
        ->and($company->parent()->parent()->name())->toBe('7th Cavalry Regiment')
        ->and($company->parent()->parent()->parent())->toBeNull();
})->with([
    ['A/1-7', '1-7', '1st Battalion', 'Alpha Company'],
    ['E/2-7', '2-7', '2nd Battalion', 'Echo Company'],
    ['C/3-7', '3-7', '3rd Battalion', 'Charlie Company'],
    // B/ACD is a WW2 unit called Baker Company; the rest of the ACD keeps the NATO names.
    ['B/ACD', 'ACD', 'Auxiliary Combat Detachment', 'Baker Company'],
    ['A/ACD', 'ACD', 'Auxiliary Combat Detachment', 'Alpha Company'],
    ['C/ACD', 'ACD', 'Auxiliary Combat Detachment', 'Charlie Company'],
    ['D/DEVCOM', 'DEVCOM', 'DEVCOM', 'Delta Company'],
    ['A/11-7', '11-7', '11th Battalion', 'Alpha Company'],
]);

it('treats every non-company group as not a line unit', function (string $group) {
    expect(CavDesignation::companyGroup($group))->toBeNull();
})->with([
    'Regimental HQ', 'Support Attachment', '1-7 Command', 'ACD Command', 'Start-Up Games',
    'New Recruits', 'Extended Leave of Absence', '7th Cavalry Reservists', 'Retired',
    'Wall of Honor', 'Rest in Peace', 'Discharged', 'a/1-7', 'AB/1-7', 'A/', 'A/1-7/x',
]);

it('reads the node a billet title names inside its company', function (string $title, UnitType $type, string $callsign, string $name) {
    $designation = CavDesignation::fromPositionTitle($title, cdCompany());

    expect($designation->type)->toBe($type)
        ->and($designation->callsign())->toBe($callsign)
        ->and($designation->name())->toBe($name);
})->with([
    ['Commander A/1-7', UnitType::Company, 'A/1-7', 'Alpha Company'],
    ['First Sergeant A/1-7', UnitType::Company, 'A/1-7', 'Alpha Company'],
    ['Platoon Leader 1/A/1-7', UnitType::Platoon, '1/A/1-7', '1st Platoon'],
    ['Platoon Sergeant 2/A/1-7', UnitType::Platoon, '2/A/1-7', '2nd Platoon'],
    ['Section Leader 1/1/A/1-7', UnitType::Section, '1/1/A/1-7', '1st Section'],
    ['Assistant Section Leader 2/1/A/1-7', UnitType::Section, '2/1/A/1-7', '2nd Section'],
    ['Trooper 3/2/A/1-7', UnitType::Section, '3/2/A/1-7', '3rd Section'],
    ['Trooper 12/4/A/1-7', UnitType::Section, '12/4/A/1-7', '12th Section'],
]);

it('chains a section up through its platoon and company', function () {
    $section = CavDesignation::fromPositionTitle('Trooper 2/1/A/1-7', cdCompany());

    expect($section->parent()->callsign())->toBe('1/A/1-7')
        ->and($section->parent()->type)->toBe(UnitType::Platoon)
        ->and($section->parent()->parent()->callsign())->toBe('A/1-7');
});

it('refuses to guess a title it cannot parse', function (string $title) {
    expect(CavDesignation::fromPositionTitle($title, cdCompany()))->toBeNull();
})->with([
    'no designation' => 'Company Clerk',
    'designation only, no role' => 'A/1-7',
    'another company (the live stray)' => 'Section Leader 3/2/C/1-7',
    'another battalion' => 'Trooper 1/1/A/2-7',
    'three levels below company' => 'Trooper 1/1/1/A/1-7',
    'zero is not an ordinal' => 'Trooper 0/1/A/1-7',
    'not a number' => 'Trooper x/1/A/1-7',
    'glued to the role' => 'Trooper1/1/A/1-7',
    'suffix inside a longer token' => 'Trooper 1/1/BA/1-7',
]);

it('only resolves titles against a company', function () {
    CavDesignation::fromPositionTitle('Trooper 1/1/A/1-7', CavDesignation::regiment());
})->throws(InvalidArgumentException::class);
