<?php

use App\Models\AuditLogEntry;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;

it('runs the mutation and records it when no reason is required', function () {
    $actor = User::factory()->create();
    $units = app(UnitRepository::class);

    $unit = app(AuditEnvelope::class)->record(
        $actor->id,
        'unit.created',
        null,
        reasonRequired: false,
        mutation: fn () => $units->create(UnitType::Regiment, '7th Cavalry Regiment', null, null),
    );

    expect(AuditLogEntry::count())->toBe(1)
        ->and(AuditLogEntry::first()->target_id)->toBe((string) $unit->id);
});

it('rejects a reason-required action given no reason, before the mutation runs', function () {
    $actor = User::factory()->create();
    $mutationRan = false;

    expect(fn () => app(AuditEnvelope::class)->record(
        $actor->id,
        'personnel.promoted',
        null,
        reasonRequired: true,
        mutation: function () use (&$mutationRan) {
            $mutationRan = true;
        },
    ))->toThrow(ReasonRequiredException::class, 'The action "personnel.promoted" requires a reason before it can be recorded.');

    expect($mutationRan)->toBeFalse()
        ->and(AuditLogEntry::count())->toBe(0);
});

it('allows a reason-required action when a reason is given', function () {
    $actor = User::factory()->create();
    $units = app(UnitRepository::class);

    app(AuditEnvelope::class)->record(
        $actor->id,
        'personnel.promoted',
        'Meets time-in-grade requirement',
        reasonRequired: true,
        mutation: fn () => $units->create(UnitType::Regiment, '7th Cavalry Regiment', null, null),
    );

    expect(AuditLogEntry::first()->reason)->toBe('Meets time-in-grade requirement');
});
