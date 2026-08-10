<?php

use App\Commands\Units\CreateUnit;
use App\Models\UnitType;
use App\Repositories\UnitRepository;

it('delegates to the repository so the write and its path computation stay one transaction', function () {
    $unit = app(CreateUnit::class)->handle(UnitType::Battalion, '1st Battalion', '1-7', null);

    expect($unit->name)->toBe('1st Battalion')
        ->and($unit->path)->toBe("{$unit->id}/");
});

it('creates a child under the given parent', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $battalion = app(CreateUnit::class)->handle(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    expect($battalion->parent_id)->toBe($regiment->id);
});
