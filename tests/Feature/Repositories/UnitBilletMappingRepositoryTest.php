<?php

use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitType;
use App\Repositories\UnitBilletMappingRepository;
use Illuminate\Database\QueryException;

// 2.5 / AD-18: this table is the documented, deterministic bridge from a Cav billet to a portal
// section — these tests exercise the one rule that keeps the mapping trustworthy: it only ever
// resolves to a `units` row of type `section`.

it('maps a cav_position_id to its section unit id', function () {
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    UnitBilletMapping::factory()->create(['unit_id' => $section->id, 'cav_position_id' => 773]);

    $map = app(UnitBilletMappingRepository::class)->sectionIdsByPositionId();

    expect($map->get(773))->toBe($section->id);
});

it('excludes a mapping whose target unit is not a section', function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);
    UnitBilletMapping::factory()->create(['unit_id' => $platoon->id, 'cav_position_id' => 501]);

    $map = app(UnitBilletMappingRepository::class)->sectionIdsByPositionId();

    expect($map->has(501))->toBeFalse();
});

it('returns every mapping keyed by cav_position_id', function () {
    $sectionA = Unit::factory()->create(['type' => UnitType::Section]);
    $sectionB = Unit::factory()->create(['type' => UnitType::Section]);
    UnitBilletMapping::factory()->create(['unit_id' => $sectionA->id, 'cav_position_id' => 1]);
    UnitBilletMapping::factory()->create(['unit_id' => $sectionB->id, 'cav_position_id' => 2]);

    $map = app(UnitBilletMappingRepository::class)->sectionIdsByPositionId();

    expect($map->all())->toBe([1 => $sectionA->id, 2 => $sectionB->id]);
});

it('returns an empty map when no mappings exist', function () {
    $map = app(UnitBilletMappingRepository::class)->sectionIdsByPositionId();

    expect($map->isEmpty())->toBeTrue();
});

it('rejects a second mapping for a cav_position_id already mapped — the unique index that makes this a deterministic function', function () {
    $sectionA = Unit::factory()->create(['type' => UnitType::Section]);
    $sectionB = Unit::factory()->create(['type' => UnitType::Section]);
    UnitBilletMapping::factory()->create(['unit_id' => $sectionA->id, 'cav_position_id' => 773]);

    expect(fn () => UnitBilletMapping::factory()->create(['unit_id' => $sectionB->id, 'cav_position_id' => 773]))
        ->toThrow(QueryException::class);
});
