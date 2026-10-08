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

it('renames a unit without touching its path or parent', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    $renamed = $repo->rename($battalion, '3rd Battalion', '3-7');

    expect($renamed->name)->toBe('3rd Battalion')
        ->and($renamed->callsign)->toBe('3-7')
        ->and($renamed->parent_id)->toBe($regiment->id)
        ->and($renamed->path)->toBe($battalion->path);
});

it('allows a null callsign on rename', function () {
    $repo = app(UnitRepository::class);
    $unit = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);

    $renamed = $repo->rename($unit, '7th Cavalry Regiment', null);

    expect($renamed->callsign)->toBeNull();
});

it('recomputes the path of the moved node when re-parented', function () {
    // AD-18: "recomputed transactionally on any edit" — the moved node's own path must extend
    // the *new* parent's path, not keep the old ancestor chain.
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalionA->id);

    $moved = $repo->move($company, $battalionB->id);

    expect($moved->parent_id)->toBe($battalionB->id)
        ->and($moved->path)->toBe("{$battalionB->path}{$company->id}/");
});

it('recomputes the path of every descendant when a subtree is re-parented', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalionA->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $section = $repo->create(UnitType::Section, 'Nightriders', '1/1/A/1-7', $platoon->id);

    $repo->move($company, $battalionB->id);

    $newCompanyPath = "{$battalionB->path}{$company->id}/";
    expect(Unit::find($platoon->id)->path)->toBe("{$newCompanyPath}{$platoon->id}/")
        ->and(Unit::find($section->id)->path)->toBe("{$newCompanyPath}{$platoon->id}/{$section->id}/");
});

it('leaves siblings and ancestors untouched by a re-parent', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalionA->id);
    $cousinCompany = $repo->create(UnitType::Company, 'Foxtrot Company', 'F/1-7', $battalionA->id);
    $regimentPath = $regiment->path;
    $battalionAPath = $battalionA->path;

    $repo->move($company, $battalionB->id);

    expect(Unit::find($regiment->id)->path)->toBe($regimentPath)
        ->and(Unit::find($battalionA->id)->path)->toBe($battalionAPath)
        ->and(Unit::find($cousinCompany->id)->path)->toBe("{$battalionAPath}{$cousinCompany->id}/");
});

it('deletes a childless unit', function () {
    $repo = app(UnitRepository::class);
    $unit = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $repo->delete($unit);

    expect(Unit::find($unit->id))->toBeNull();
});

it('reports whether a unit has children', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    expect($repo->hasChildren($regiment))->toBeTrue()
        ->and($repo->hasChildren($battalion))->toBeFalse();
});

// 5.7 / AD-13: a non-leaf report aggregates its *direct* children only — never a grandchild.
it('lists a unit\'s direct children in creation order, not grandchildren or siblings', function () {
    $repo = app(UnitRepository::class);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $first = $repo->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $company->id);
    $second = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $repo->create(UnitType::Section, '1st Squad', '1/1/A/1-7', $first->id);
    $repo->create(UnitType::Company, 'Bravo Company', 'B/1-7', null);
    // Sibling paths sort as strings — a 10/ sorts before a 9/ — so creation order is by id alone.
    $first->forceFill(['path' => "{$company->path}9/"])->save();
    $second->forceFill(['path' => "{$company->path}10/"])->save();

    expect($repo->children($company)->modelKeys())->toBe([$first->id, $second->id])
        ->and($repo->children($second))->toBeEmpty();
});

it('finds every unit carrying a callsign, in id order (#115)', function () {
    $repository = app(UnitRepository::class);
    $company = $repository->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $repository->create(UnitType::Company, 'Bravo Company', 'B/1-7', null);
    $twin = $repository->create(UnitType::Company, 'Alpha (copy)', 'A/1-7', null);

    expect($repository->withCallsign('A/1-7')->modelKeys())->toBe([$company->id, $twin->id])
        ->and($repository->withCallsign('Z/9-9'))->toBeEmpty();
});

// 5.8: the roll-up reads a whole chain in one query and rebuilds it from parent_id.
it('lists a unit\'s whole subtree, itself included, in id order and nothing outside it', function () {
    $repo = app(UnitRepository::class);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $other = $repo->create(UnitType::Company, 'Bravo Company', 'B/1-7', null);
    $repo->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $other->id);

    expect($repo->subtree($company)->modelKeys())->toBe([$company->id, $platoon->id, $section->id])
        ->and($repo->subtree($section)->modelKeys())->toBe([$section->id]);
});
