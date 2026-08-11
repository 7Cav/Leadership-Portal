<?php

use App\Models\AuditLogEntry;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\UnitRepository;

it('writes exactly one audit_log row derived from the mutation result', function () {
    $actor = User::factory()->create();
    $units = app(UnitRepository::class);

    $unit = app(AuditLogRepository::class)->recordWithMutation(
        $actor->id,
        'unit.created',
        null,
        fn () => $units->create(UnitType::Regiment, '7th Cavalry Regiment', null, null),
    );

    expect(AuditLogEntry::count())->toBe(1);

    $entry = AuditLogEntry::first();
    $attributes = $unit->getAttributes();
    ksort($attributes);

    expect($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('unit.created')
        ->and($entry->target_type)->toBe('Unit')
        ->and($entry->target_id)->toBe((string) $unit->id)
        ->and($entry->reason)->toBeNull()
        ->and($entry->content_digest)->toBe(hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR)));
});

it('digests two units with different content differently', function () {
    $actor = User::factory()->create();
    $units = app(UnitRepository::class);

    app(AuditLogRepository::class)->recordWithMutation(
        $actor->id,
        'unit.created',
        null,
        fn () => $units->create(UnitType::Regiment, '7th Cavalry Regiment', null, null),
    );
    app(AuditLogRepository::class)->recordWithMutation(
        $actor->id,
        'unit.created',
        null,
        fn () => $units->create(UnitType::Regiment, '1st Cavalry Regiment', null, null),
    );

    $digests = AuditLogEntry::pluck('content_digest');
    expect($digests->unique())->toHaveCount(2);
});

it('refuses to log a mutation result that is not an Eloquent Model', function () {
    $actor = User::factory()->create();

    expect(fn () => app(AuditLogRepository::class)->recordWithMutation(
        $actor->id,
        'unit.created',
        null,
        fn () => null,
    ))->toThrow(RuntimeException::class, 'must return an Eloquent Model');

    expect(AuditLogEntry::count())->toBe(0);
});

it('persists the given reason', function () {
    $actor = User::factory()->create();
    $units = app(UnitRepository::class);

    app(AuditLogRepository::class)->recordWithMutation(
        $actor->id,
        'unit.created',
        'Reorganizing the chain of command',
        fn () => $units->create(UnitType::Regiment, '7th Cavalry Regiment', null, null),
    );

    expect(AuditLogEntry::first()->reason)->toBe('Reorganizing the chain of command');
});

it('rolls back the mutation and leaves no orphan log when the mutation throws', function () {
    $actor = User::factory()->create();

    expect(fn () => app(AuditLogRepository::class)->recordWithMutation(
        $actor->id,
        'unit.created',
        null,
        function () {
            throw new RuntimeException('mutation failed');
        },
    ))->toThrow(RuntimeException::class, 'mutation failed');

    expect(AuditLogEntry::count())->toBe(0);
});

it('rolls back a mutation that writes and then throws, leaving neither the write nor the log', function () {
    $actor = User::factory()->create();
    $units = app(UnitRepository::class);

    expect(fn () => app(AuditLogRepository::class)->recordWithMutation(
        $actor->id,
        'unit.created',
        null,
        function () use ($units) {
            $units->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

            throw new RuntimeException('failed after the write');
        },
    ))->toThrow(RuntimeException::class, 'failed after the write');

    expect(AuditLogEntry::count())->toBe(0)
        ->and(Unit::count())->toBe(0);
});
