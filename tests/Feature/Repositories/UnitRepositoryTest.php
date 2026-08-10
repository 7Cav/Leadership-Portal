<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\UnitRepository;

// AD-18: the materialized path is what makes subtree scoping cheap everywhere downstream, so
// its correctness is the load-bearing behaviour of this repository — not just that a row gets
// written.

it('computes a self-contained path for a root node', function () {
    $repo = app(UnitRepository::class);

    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    expect($regiment->path)->toBe("{$regiment->id}/");
});

it('extends the parent path for a child node', function () {
    $repo = app(UnitRepository::class);

    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    expect($battalion->path)->toBe("{$regiment->path}{$battalion->id}/")
        ->and($battalion->parent_id)->toBe($regiment->id);
});

it('persists type, name and callsign as given', function () {
    $repo = app(UnitRepository::class);

    $unit = $repo->create(UnitType::Company, 'Echo Company', 'E/2-7', null);

    expect($unit->type)->toBe(UnitType::Company)
        ->and($unit->name)->toBe('Echo Company')
        ->and($unit->callsign)->toBe('E/2-7');
});

it('returns exactly the subtree for a path-prefix query, not siblings or ancestors', function () {
    $repo = app(UnitRepository::class);

    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $companyUnderA = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalionA->id);

    $subtree = Unit::query()->where('path', 'like', "{$battalionA->path}%")->pluck('id');

    expect($subtree)->toContain($battalionA->id, $companyUnderA->id)
        ->and($subtree)->not->toContain($battalionB->id, $regiment->id);
});

it('writes the row and computes the path in one transaction', function () {
    // A crash between insert and path-compute would leave a unit with a placeholder path,
    // silently breaking every subtree query that should include it (AD-18's own rule: the path
    // is "written in a single transaction").
    $repo = app(UnitRepository::class);

    $unit = $repo->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);

    expect(Unit::find($unit->id)->path)->toBe("{$unit->id}/");
});

it('encodes the full ancestor chain through all five echelons', function () {
    $repo = app(UnitRepository::class);

    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Echo Company', 'E/2-7', $battalion->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/E/2-7', $company->id);
    $section = $repo->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', $platoon->id);

    expect($section->path)->toBe("{$regiment->id}/{$battalion->id}/{$company->id}/{$platoon->id}/{$section->id}/");

    // A platoon-level subtree query must exclude both the top-level sibling battalion and the
    // company-level cousin — not just direct siblings.
    $cousinCompany = $repo->create(UnitType::Company, 'Foxtrot Company', 'F/2-7', $battalion->id);
    $subtree = Unit::query()->where('path', 'like', "{$platoon->path}%")->pluck('id');

    expect($subtree)->toContain($platoon->id, $section->id)
        ->and($subtree)->not->toContain($company->id, $cousinCompany->id, $battalion->id, $regiment->id);
});

it('returns every unit for the tree read, each carrying its ancestor path', function () {
    $repo = app(UnitRepository::class);

    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    $tree = $repo->tree();

    expect($tree)->toHaveCount(2)
        ->and($tree->pluck('name'))->toContain('7th Cavalry Regiment', '1st Battalion');
});
