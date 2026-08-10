<?php

use App\Commands\Units\UpdateUnit;
use App\Models\AuditLogEntry;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

it('delegates the rename to the repository', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $unit = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $renamed = app(UpdateUnit::class)->handle($actor, $unit, '7th Cav Regiment', '7CAV');

    expect($renamed->name)->toBe('7th Cav Regiment')
        ->and($renamed->callsign)->toBe('7CAV');
});

it('records exactly one audit_log entry for the rename', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $unit = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    app(UpdateUnit::class)->handle($actor, $unit, '7th Cav Regiment', '7CAV');

    expect(AuditLogEntry::count())->toBe(1);

    $entry = AuditLogEntry::first();
    expect($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('unit.renamed')
        ->and($entry->target_type)->toBe('Unit')
        ->and($entry->target_id)->toBe((string) $unit->id)
        ->and($entry->reason)->toBeNull();
});
