<?php

use App\Commands\Units\CreateUnit;
use App\Commands\Units\InvalidUnitPlacementException;
use App\Models\UnitType;
use App\Repositories\UnitRepository;

it('delegates to the repository so the write and its path computation stay one transaction', function () {
    $unit = app(CreateUnit::class)->handle(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    expect($unit->name)->toBe('7th Cavalry Regiment')
        ->and($unit->path)->toBe("{$unit->id}/");
});

it('creates a child exactly one rank below its parent', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $battalion = app(CreateUnit::class)->handle(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    expect($battalion->parent_id)->toBe($regiment->id);
});

it('refuses a non-Regiment root node', function () {
    expect(fn () => app(CreateUnit::class)->handle(UnitType::Battalion, '2nd Battalion', '2-7', null))
        ->toThrow(InvalidUnitPlacementException::class, 'A root node (no parent) must be a Regiment, not battalion.');
});

it('refuses a child that is not exactly one rank below its parent', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    // Skips a rank (Regiment -> Company, should be Regiment -> Battalion).
    expect(fn () => app(CreateUnit::class)->handle(UnitType::Company, 'Echo Company', 'E/2-7', $regiment->id))
        ->toThrow(InvalidUnitPlacementException::class, 'A company cannot be placed under a regiment — it must sit exactly one rank below its parent.');
});

it('refuses a child placed under a lower-echelon parent', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    // A battalion cannot be placed under its own child battalion.
    expect(fn () => app(CreateUnit::class)->handle(UnitType::Battalion, '1st Battalion', '1-7', $battalion->id))
        ->toThrow(InvalidUnitPlacementException::class);
});
