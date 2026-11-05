<?php

use App\Models\Unit;
use App\Repositories\UnitRepository;

it('gives every unit an unguessable roll call image token', function () {
    $a = Unit::factory()->create();
    $b = Unit::factory()->create();

    expect($a->fresh()->rollcall_image_token)->toBeString()->toHaveLength(40)
        ->and($a->fresh()->rollcall_image_token)->not->toBe($b->fresh()->rollcall_image_token);
});

it('finds a unit by its image token and by nothing else', function () {
    $unit = Unit::factory()->create();
    $repo = app(UnitRepository::class);

    expect($repo->findByRollcallImageToken($unit->fresh()->rollcall_image_token)?->id)->toBe($unit->id)
        ->and($repo->findByRollcallImageToken('nope'))->toBeNull()
        ->and($repo->findByRollcallImageToken(''))->toBeNull();
});
