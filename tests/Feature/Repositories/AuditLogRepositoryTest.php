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

it('lists entries most-recent-first', function () {
    $actor = User::factory()->create();
    $older = AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'created_at' => now()->subDay()]);
    $newer = AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'created_at' => now()]);

    $entries = app(AuditLogRepository::class)->filtered();

    expect($entries->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

it('eager-loads the actor relation so a caller never lazy-loads it', function () {
    $actor = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $actor->id]);

    $entries = app(AuditLogRepository::class)->filtered();

    expect($entries->first()->relationLoaded('actor'))->toBeTrue();
});

it('filters by actor', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $actor->id]);
    AuditLogEntry::factory()->create(['actor_id' => $other->id]);

    $entries = app(AuditLogRepository::class)->filtered(actorId: $actor->id);

    expect($entries->pluck('actor_id')->all())->toBe([$actor->id]);
});

it('filters by action', function () {
    $actor = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.created']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.deleted']);

    $entries = app(AuditLogRepository::class)->filtered(action: 'unit.deleted');

    expect($entries->pluck('action')->all())->toBe(['unit.deleted']);
});

it('filters by target type and target id', function () {
    $actor = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'target_type' => 'Unit', 'target_id' => '1']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'target_type' => 'Unit', 'target_id' => '2']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'target_type' => 'PortalRoleGrant', 'target_id' => '1']);

    $entries = app(AuditLogRepository::class)->filtered(targetType: 'Unit', targetId: '1');

    expect($entries->pluck('id')->all())->toHaveCount(1)
        ->and($entries->first()->target_type)->toBe('Unit')
        ->and($entries->first()->target_id)->toBe('1');
});

it('filters by a date range, inclusive of both ends', function () {
    $actor = User::factory()->create();
    $before = AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'created_at' => '2026-05-01 12:00:00']);
    $inRange = AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'created_at' => '2026-05-10 12:00:00']);
    $after = AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'created_at' => '2026-05-20 12:00:00']);

    $entries = app(AuditLogRepository::class)->filtered(dateFrom: '2026-05-05', dateTo: '2026-05-15');

    expect($entries->pluck('id')->all())->toBe([$inRange->id]);
});

it('combines multiple filters with AND', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $match = AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.created']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.deleted']);
    AuditLogEntry::factory()->create(['actor_id' => $other->id, 'action' => 'unit.created']);

    $entries = app(AuditLogRepository::class)->filtered(actorId: $actor->id, action: 'unit.created');

    expect($entries->pluck('id')->all())->toBe([$match->id]);
});

it('paginates results', function () {
    $actor = User::factory()->create();
    AuditLogEntry::factory()->count(30)->create(['actor_id' => $actor->id]);

    $entries = app(AuditLogRepository::class)->filtered(perPage: 10);

    expect($entries->count())->toBe(10)
        ->and($entries->total())->toBe(30);
});

it('lists distinct actors who appear in the log, ordered by username', function () {
    $zed = User::factory()->create(['username' => 'zed']);
    $amy = User::factory()->create(['username' => 'amy']);
    $unused = User::factory()->create(['username' => 'never-logged']);
    AuditLogEntry::factory()->create(['actor_id' => $zed->id]);
    AuditLogEntry::factory()->create(['actor_id' => $amy->id]);

    $actors = app(AuditLogRepository::class)->actors();

    expect($actors->pluck('id')->all())->toBe([$amy->id, $zed->id])
        ->and($actors->pluck('id'))->not->toContain($unused->id);
});

it('lists distinct actions present in the log, alphabetically', function () {
    $actor = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.deleted']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.created']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.created']);

    $actions = app(AuditLogRepository::class)->actions();

    expect($actions->all())->toBe(['unit.created', 'unit.deleted']);
});
