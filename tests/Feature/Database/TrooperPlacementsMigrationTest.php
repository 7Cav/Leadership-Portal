<?php

use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperPlacement;
use App\Models\Unit;
use App\Models\UnitType;
use Illuminate\Support\Facades\Schema;

// The placement history (2026-10-27) starts with every trooper placed today: one open row from the beginning, so every
// week before the history existed reads against the unit they are in, as before.

function trooperPlacementsMigration(): object
{
    return require database_path('migrations/2026_10_27_100000_create_trooper_placements_table.php');
}

it('backfills one open row per placed trooper, from the beginning, with their placement source', function () {
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    $synced = Trooper::factory()->create();
    $synced->forceFill(['primary_section_id' => $section->id, 'placement_source' => PlacementSource::Sync])->save();
    $manual = Trooper::factory()->create();
    $manual->forceFill(['primary_section_id' => $section->id, 'placement_source' => PlacementSource::Manual])->save();
    $unknown = Trooper::factory()->create();
    $unknown->forceFill(['primary_section_id' => $section->id, 'placement_source' => null])->save();
    Trooper::factory()->create(['primary_section_id' => null]);

    trooperPlacementsMigration()->down();
    trooperPlacementsMigration()->up();

    $rows = TrooperPlacement::query()->orderBy('trooper_id')->get();

    expect($rows->map(fn (TrooperPlacement $row) => [$row->trooper_id, $row->unit_id, $row->placed_from, $row->placed_until, $row->source])->all())->toBe([
        [$synced->id, $section->id, null, null, PlacementSource::Sync],
        [$manual->id, $section->id, null, null, PlacementSource::Manual],
        [$unknown->id, $section->id, null, null, PlacementSource::Sync],
    ]);
});

it('rolls back by dropping the table, and re-applies', function () {
    trooperPlacementsMigration()->down();
    expect(Schema::hasTable('trooper_placements'))->toBeFalse();

    trooperPlacementsMigration()->up();
    expect(Schema::hasTable('trooper_placements'))->toBeTrue();
});

it('drops a trooper\'s history with the trooper and keeps the stretch when its unit goes', function () {
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    $trooper = Trooper::factory()->create();
    TrooperPlacement::query()->create(['trooper_id' => $trooper->id, 'unit_id' => $section->id, 'source' => 'sync']);

    $section->delete();
    expect(TrooperPlacement::query()->sole()->unit_id)->toBeNull();

    $trooper->delete();
    expect(TrooperPlacement::query()->count())->toBe(0);
});
