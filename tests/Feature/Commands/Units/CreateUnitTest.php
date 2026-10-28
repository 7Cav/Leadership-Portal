<?php

use App\Commands\Units\CreateUnit;
use App\Commands\Units\InvalidUnitPlacementException;
use App\Models\AuditLogEntry;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

it('delegates to the repository so the write and its path computation stay one transaction', function () {
    $actor = User::factory()->admin()->create();

    $unit = app(CreateUnit::class)->handle($actor, UnitType::Regiment, '7th Cavalry Regiment', null, null);

    expect($unit->name)->toBe('7th Cavalry Regiment')
        ->and($unit->path)->toBe("{$unit->id}/");
});

it('records exactly one audit_log entry for the created unit', function () {
    $actor = User::factory()->admin()->create();

    $unit = app(CreateUnit::class)->handle($actor, UnitType::Regiment, '7th Cavalry Regiment', null, null);

    expect(AuditLogEntry::count())->toBe(1);

    $entry = AuditLogEntry::first();
    expect($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('unit.created')
        ->and($entry->target_type)->toBe('Unit')
        ->and($entry->target_id)->toBe((string) $unit->id)
        ->and($entry->reason)->toBeNull()
        ->and($entry->content_digest)->not->toBeEmpty();
});

it('creates a child exactly one rank below its parent', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $battalion = app(CreateUnit::class)->handle($actor, UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    expect($battalion->parent_id)->toBe($regiment->id);
});

it('refuses a non-Regiment root node', function () {
    $actor = User::factory()->admin()->create();

    expect(fn () => app(CreateUnit::class)->handle($actor, UnitType::Battalion, '2nd Battalion', '2-7', null))
        ->toThrow(InvalidUnitPlacementException::class, 'A unit with no parent must be a regiment, not a battalion.');

    expect(AuditLogEntry::count())->toBe(0);
});

it('refuses a child that is not exactly one rank below its parent', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    // Skips a rank (Regiment -> Company, should be Regiment -> Battalion).
    expect(fn () => app(CreateUnit::class)->handle($actor, UnitType::Company, 'Echo Company', 'E/2-7', $regiment->id))
        ->toThrow(InvalidUnitPlacementException::class, 'A company cannot be placed under a regiment: it must sit exactly one level below its parent.');
});

it('refuses a child placed under a lower-echelon parent', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    // A battalion cannot be placed under its own child battalion.
    expect(fn () => app(CreateUnit::class)->handle($actor, UnitType::Battalion, '1st Battalion', '1-7', $battalion->id))
        ->toThrow(InvalidUnitPlacementException::class);
});
