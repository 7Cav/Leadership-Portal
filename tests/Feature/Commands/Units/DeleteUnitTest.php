<?php

use App\Commands\Units\DeleteUnit;
use App\Commands\Units\UnitHasDependentsException;
use App\Models\AuditLogEntry;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

it('deletes a leaf unit', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $unit = $repo->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);

    app(DeleteUnit::class)->handle($actor, $unit);

    expect(Unit::find($unit->id))->toBeNull();
});

it('records exactly one audit_log entry for the delete', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $unit = $repo->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);
    $unitId = $unit->id;

    app(DeleteUnit::class)->handle($actor, $unit);

    expect(AuditLogEntry::count())->toBe(1);

    $entry = AuditLogEntry::first();
    expect($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('unit.deleted')
        ->and($entry->target_type)->toBe('Unit')
        ->and($entry->target_id)->toBe((string) $unitId);
});

it('refuses to delete a unit that still has children', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    expect(fn () => app(DeleteUnit::class)->handle($actor, $regiment))
        ->toThrow(UnitHasDependentsException::class, 'Cannot delete "7th Cavalry Regiment" — it still has child units. Re-parent or remove them first.');

    expect(Unit::find($regiment->id))->not->toBeNull();
    expect(AuditLogEntry::count())->toBe(0);
});
