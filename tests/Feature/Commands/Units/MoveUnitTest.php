<?php

use App\Commands\Units\InvalidUnitPlacementException;
use App\Commands\Units\MoveUnit;
use App\Models\AuditLogEntry;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

it('delegates a valid re-parent to the repository', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalionA->id);

    $moved = app(MoveUnit::class)->handle($actor, $company, $battalionB->id);

    expect($moved->parent_id)->toBe($battalionB->id)
        ->and($moved->path)->toBe("{$battalionB->path}{$company->id}/");
});

it('records exactly one audit_log entry for the move', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalionA->id);

    app(MoveUnit::class)->handle($actor, $company, $battalionB->id);

    expect(AuditLogEntry::count())->toBe(1);

    $entry = AuditLogEntry::first();
    expect($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('unit.moved')
        ->and($entry->target_type)->toBe('Unit')
        ->and($entry->target_id)->toBe((string) $company->id);
});

it('is a no-op when the parent is unchanged, and logs nothing', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $originalPath = $battalion->path;

    $result = app(MoveUnit::class)->handle($actor, $battalion, $regiment->id);

    expect($result->path)->toBe($originalPath);
    expect(AuditLogEntry::count())->toBe(0);
});

it('refuses to re-parent a non-Regiment to root', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    expect(fn () => app(MoveUnit::class)->handle($actor, $battalion, null))
        ->toThrow(InvalidUnitPlacementException::class, 'A unit with no parent must be a regiment, not a battalion.');
});

it('refuses to re-parent under the wrong echelon rank', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    // A battalion must sit under a regiment, not under another battalion.
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    expect(fn () => app(MoveUnit::class)->handle($actor, $battalion, $battalionB->id))
        ->toThrow(InvalidUnitPlacementException::class);

    expect(AuditLogEntry::count())->toBe(0);
});

it('refuses to re-parent a node under one of its own descendants (a cycle)', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);

    expect(fn () => app(MoveUnit::class)->handle($actor, $battalion, $company->id))
        ->toThrow(InvalidUnitPlacementException::class);
});

it('refuses to re-parent a node under itself', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    expect(fn () => app(MoveUnit::class)->handle($actor, $battalion, $battalion->id))
        ->toThrow(InvalidUnitPlacementException::class);
});
