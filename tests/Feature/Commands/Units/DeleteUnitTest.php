<?php

use App\Commands\Units\DeleteUnit;
use App\Commands\Units\UnitHasDependentsException;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\UnitRepository;

it('deletes a leaf unit', function () {
    $repo = app(UnitRepository::class);
    $unit = $repo->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);

    app(DeleteUnit::class)->handle($unit);

    expect(Unit::find($unit->id))->toBeNull();
});

it('refuses to delete a unit that still has children', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    expect(fn () => app(DeleteUnit::class)->handle($regiment))
        ->toThrow(UnitHasDependentsException::class, 'Cannot delete "7th Cavalry Regiment" — it still has child units. Re-parent or remove them first.');

    expect(Unit::find($regiment->id))->not->toBeNull();
});
