<?php

use App\Commands\Units\InvalidUnitPlacementException;
use App\Commands\Units\MoveUnit;
use App\Models\UnitType;
use App\Repositories\UnitRepository;

it('delegates a valid re-parent to the repository', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalionA->id);

    $moved = app(MoveUnit::class)->handle($company, $battalionB->id);

    expect($moved->parent_id)->toBe($battalionB->id)
        ->and($moved->path)->toBe("{$battalionB->path}{$company->id}/");
});

it('is a no-op when the parent is unchanged', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $originalPath = $battalion->path;

    $result = app(MoveUnit::class)->handle($battalion, $regiment->id);

    expect($result->path)->toBe($originalPath);
});

it('refuses to re-parent a non-Regiment to root', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    expect(fn () => app(MoveUnit::class)->handle($battalion, null))
        ->toThrow(InvalidUnitPlacementException::class, 'A root node (no parent) must be a Regiment, not battalion.');
});

it('refuses to re-parent under the wrong echelon rank', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    // A battalion must sit under a regiment, not under another battalion.
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    expect(fn () => app(MoveUnit::class)->handle($battalion, $battalionB->id))
        ->toThrow(InvalidUnitPlacementException::class);
});

it('refuses to re-parent a node under one of its own descendants (a cycle)', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);

    expect(fn () => app(MoveUnit::class)->handle($battalion, $company->id))
        ->toThrow(InvalidUnitPlacementException::class);
});

it('refuses to re-parent a node under itself', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    expect(fn () => app(MoveUnit::class)->handle($battalion, $battalion->id))
        ->toThrow(InvalidUnitPlacementException::class);
});
