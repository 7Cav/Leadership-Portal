<?php

use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Repositories\TrooperRepository;

// Story 2.7: the repository's two placement writes, and which one set a trooper's section.

it('places an unplaced trooper and marks the placement manual', function () {
    $section = Unit::factory()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $placed = app(TrooperRepository::class)->place($trooper->id, $section->id);

    expect($placed?->primary_section_id)->toBe($section->id)
        ->and($placed->placement_source)->toBe(PlacementSource::Manual);
});

it('does not apply to a trooper who is already placed, and changes nothing', function () {
    $current = Unit::factory()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => $current->id, 'placement_source' => PlacementSource::Sync]);

    expect(app(TrooperRepository::class)->place($trooper->id, Unit::factory()->create()->id))->toBeNull();

    $fresh = $trooper->fresh();
    expect($fresh->primary_section_id)->toBe($current->id)
        ->and($fresh->placement_source)->toBe(PlacementSource::Sync);
});

it('does not apply to a departed trooper or to an id that is not a trooper', function () {
    $section = Unit::factory()->create();
    $departed = Trooper::factory()->create(['primary_section_id' => null, 'status' => TrooperStatus::Inactive]);
    $repo = app(TrooperRepository::class);

    expect($repo->place($departed->id, $section->id))->toBeNull()
        ->and($repo->place(999999, $section->id))->toBeNull()
        ->and($departed->fresh()->primary_section_id)->toBeNull();
});

it('marks a placement the sync mapping makes as a sync placement', function () {
    $section = Unit::factory()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    app(TrooperRepository::class)->updatePrimarySections([$trooper->cav_id => $section->id]);

    expect($trooper->fresh()->placement_source)->toBe(PlacementSource::Sync);
});

it('lets the sync mapping take over a manual placement once Cav can resolve the billet', function () {
    $manual = Unit::factory()->create();
    $resolved = Unit::factory()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);
    $repo = app(TrooperRepository::class);
    $repo->place($trooper->id, $manual->id);

    $repo->updatePrimarySections([$trooper->cav_id => $resolved->id]);

    $fresh = $trooper->fresh();
    expect($fresh->primary_section_id)->toBe($resolved->id)
        ->and($fresh->placement_source)->toBe(PlacementSource::Sync);
});

it('leaves a manual placement alone when the mapping cannot resolve that trooper', function () {
    $manual = Unit::factory()->create();
    $other = Unit::factory()->create();
    $placed = Trooper::factory()->create(['primary_section_id' => null]);
    $someoneElse = Trooper::factory()->create(['primary_section_id' => null]);
    $repo = app(TrooperRepository::class);
    $repo->place($placed->id, $manual->id);

    // The mapping resolves someone else only; the manually placed trooper is absent from it.
    $repo->updatePrimarySections([$someoneElse->cav_id => $other->id]);

    $fresh = $placed->fresh();
    expect($fresh->primary_section_id)->toBe($manual->id)
        ->and($fresh->placement_source)->toBe(PlacementSource::Manual);
});
