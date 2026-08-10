<?php

use App\Commands\Units\UpdateUnit;
use App\Models\UnitType;
use App\Repositories\UnitRepository;

it('delegates the rename to the repository', function () {
    $repo = app(UnitRepository::class);
    $unit = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $renamed = app(UpdateUnit::class)->handle($unit, '7th Cav Regiment', '7CAV');

    expect($renamed->name)->toBe('7th Cav Regiment')
        ->and($renamed->callsign)->toBe('7CAV');
});
