<?php

use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\Unit;
use Illuminate\Support\Facades\Schema;

// Story 2.7: the migration adds `placement_source` and labels every placement made before it as the
// sync's, since the mapping step (2.5) was the only thing that could place a trooper until now.

it('adds a nullable placement_source column to troopers', function () {
    expect(Schema::hasColumn('troopers', 'placement_source'))->toBeTrue();

    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    expect($trooper->fresh()->placement_source)->toBeNull();
});

it('labels the placements that already exist as sync placements and leaves unplaced troopers null', function () {
    $migration = require database_path('migrations/2026_10_08_190000_add_placement_source_to_troopers_table.php');

    // Put the table back as it was before the migration, with one placed and one unplaced trooper.
    Schema::table('troopers', fn ($table) => $table->dropColumn('placement_source'));
    $section = Unit::factory()->create();
    $placed = Trooper::factory()->create(['primary_section_id' => $section->id]);
    $unplaced = Trooper::factory()->create(['primary_section_id' => null]);

    $migration->up();

    expect($placed->fresh()->placement_source)->toBe(PlacementSource::Sync)
        ->and($unplaced->fresh()->placement_source)->toBeNull();
});
