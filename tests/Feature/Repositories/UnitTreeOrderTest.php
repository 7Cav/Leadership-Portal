<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\DemoHistoryRepository;
use App\Repositories\UnitRepository;

// `tree()` returns units depth-first with siblings in id (creation) order. `path` is text, so a plain
// SQL ORDER BY would put "1/145/" before "1/2/" once ids pass a digit boundary.

it('keeps siblings in creation order when their ids differ in length', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    // Eleven battalions: ids 2..12, so a text sort would put "1/10/" and "1/11/" before "1/2/".
    $battalions = collect(range(1, 11))->map(fn ($n) => $repo->create(UnitType::Battalion, "Battalion {$n}", null, $regiment->id));

    expect($repo->tree()->pluck('id')->all())->toBe([$regiment->id, ...$battalions->pluck('id')->all()]);
});

it('puts every unit straight after its parent, ahead of its parent\'s next sibling', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $first = $repo->create(UnitType::Battalion, '1st Battalion', null, $regiment->id);
    $second = $repo->create(UnitType::Battalion, '2nd Battalion', null, $regiment->id);
    // Created last, so a high id, but it belongs under the first battalion.
    $company = $repo->create(UnitType::Company, 'Alpha Company', null, $first->id);

    expect($repo->tree()->pluck('id')->all())->toBe([$regiment->id, $first->id, $company->id, $second->id]);
});

it('orders a deep tree depth-first across ids of mixed lengths', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', null, $regiment->id);
    $companies = collect(range(1, 12))->map(fn ($n) => $repo->create(UnitType::Company, "Company {$n}", null, $battalion->id));
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', null, $companies->first()->id);

    $order = $repo->tree()->pluck('id')->all();

    expect($order)->toBe([$regiment->id, $battalion->id, $companies[0]->id, $platoon->id, ...$companies->slice(1)->pluck('id')->all()]);
});

it('returns an empty collection when there are no units', function () {
    expect(app(UnitRepository::class)->tree()->all())->toBe([]);
});

// The other path-ordered reads use the same depth-first order. Ids are set explicitly so the text
// order is wrong whatever ids the test database hands out: as text, "1/14/" < "1/2/145/" < "1/2/3/".
function mixedLengthTree(): array
{
    $unit = fn (int $id, UnitType $type, string $path, ?int $parent) => Unit::factory()->create(['id' => $id, 'type' => $type, 'path' => $path, 'parent_id' => $parent]);

    $unit(1, UnitType::Regiment, '1/', null);
    $unit(2, UnitType::Platoon, '1/2/', 1);
    $unit(3, UnitType::Section, '1/2/3/', 2);
    $unit(145, UnitType::Section, '1/2/145/', 2);
    $unit(14, UnitType::Platoon, '1/14/', 1);
    $unit(15, UnitType::Section, '1/14/15/', 14);

    return [1, 2, 3, 145, 14, 15];
}

it('lists sections depth-first with siblings in id order, not as text', function () {
    mixedLengthTree();

    expect(app(UnitRepository::class)->sectionsAmong([15, 145, 3, 2])->pluck('id')->all())->toBe([3, 145, 15]);
});

it('lists a demo subtree depth-first with siblings in id order, not as text', function () {
    $order = mixedLengthTree();

    expect(app(DemoHistoryRepository::class)->subtreeUnits(Unit::find(1))->pluck('id')->all())->toBe($order);
});
