<?php

use App\Commands\PortalRoleGrants\GrantStaffScope;
use App\Commands\PortalRoleGrants\GrantTargetIsAdminException;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use App\Repositories\PortalRoleGrantRepository\Eloquent as EloquentPortalRoleGrantRepository;
use App\Support\Audit\ReasonRequiredException;
use Illuminate\Support\Facades\DB;

it('grants a non-admin staff user scope at a chosen node (AC #1)', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    $grant = app(GrantStaffScope::class)->handle($actor, $target, $unit, 'S1 staff, no line billet');

    expect($grant->user_id)->toBe($target->id)
        ->and($grant->unit_id)->toBe($unit->id)
        ->and(PortalRoleGrant::count())->toBe(1);
});

it('records exactly one audit_log entry for the grant (AC #3)', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    $grant = app(GrantStaffScope::class)->handle($actor, $target, $unit, 'S1 staff, no line billet');

    expect(AuditLogEntry::count())->toBe(1);

    $entry = AuditLogEntry::first();
    expect($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('portal_role_grant.granted')
        ->and($entry->target_type)->toBe('PortalRoleGrant')
        ->and($entry->target_id)->toBe((string) $grant->id)
        ->and($entry->reason)->toBe('S1 staff, no line billet')
        ->and($entry->content_digest)->not->toBeEmpty();
});

it('refuses a grant with no reason (AD-16)', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    expect(fn () => app(GrantStaffScope::class)->handle($actor, $target, $unit, null))
        ->toThrow(ReasonRequiredException::class);

    expect(PortalRoleGrant::count())->toBe(0)
        ->and(AuditLogEntry::count())->toBe(0);
});

it('refuses to grant scope to a user who already holds the admin flag (AD-7)', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->admin()->create();
    $unit = Unit::factory()->create();

    expect(fn () => app(GrantStaffScope::class)->handle($actor, $target, $unit, 'reason'))
        ->toThrow(GrantTargetIsAdminException::class);

    expect(PortalRoleGrant::count())->toBe(0)
        ->and(AuditLogEntry::count())->toBe(0);
});

it('is idempotent on re-granting an already-held node, logging nothing for the no-op (AD-8 — never a duplicate row)', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    app(GrantStaffScope::class)->handle($actor, $target, $unit, 'first grant');
    app(GrantStaffScope::class)->handle($actor, $target, $unit, 'second grant');

    expect(PortalRoleGrant::count())->toBe(1)
        ->and(AuditLogEntry::count())->toBe(1);
});

/**
 * The real repository, except that `find()` still answers what a racing request saw before the other one
 * committed: the Command's own no-op check passes, and only the write inside the audited transaction can tell.
 */
function grantRepositorySeeing(?PortalRoleGrant $seen): PortalRoleGrantRepository
{
    return Mockery::mock(EloquentPortalRoleGrantRepository::class)->makePartial()
        ->shouldReceive('find')->andReturn($seen)->getMock();
}

it('treats a grant a racing request wrote after the no-op check as already granted, logging nothing', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    $racing = PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);
    app()->instance(PortalRoleGrantRepository::class, grantRepositorySeeing(null));

    $grant = app(GrantStaffScope::class)->handle($actor, $target, $unit, 'grant');

    expect($grant->id)->toBe($racing->id)
        ->and(PortalRoleGrant::count())->toBe(1)
        ->and(AuditLogEntry::count())->toBe(0);
});

/** Remove what a MySQL race test committed through the second connection. */
function grantRaceCleanup(int $unitId, array $userIds): void
{
    DB::connection('race')->table('portal_role_grants')->where('unit_id', $unitId)->delete();
    DB::connection('race')->table('units')->where('id', $unitId)->delete();
    DB::connection('race')->table('users')->whereIn('id', $userIds)->delete();
}

// The real race needs MySQL: the losing grant's insert meets the unique key, and inside REPEATABLE
// READ firstOrCreate's own re-read cannot see the winner's row, so it used to rethrow (a 500).
it('answers a grant committed by a racing request after this transaction\'s snapshot as already granted (MySQL)', function () {
    config(['database.connections.race' => config('database.connections.mysql')]);
    $actor = User::factory()->admin()->connection('race')->create();
    $target = User::factory()->connection('race')->create();
    $unit = Unit::factory()->connection('race')->create();
    // Committed rows: removed once the test's own transaction has rolled back and let go of its locks.
    $this->beforeApplicationDestroyed(fn () => grantRaceCleanup($unit->id, [$actor->id, $target->id]));

    PortalRoleGrant::query()->count(); // this transaction's snapshot starts here
    $racingId = DB::connection('race')->table('portal_role_grants')->insertGetId(['user_id' => $target->id, 'unit_id' => $unit->id, 'created_at' => now(), 'updated_at' => now()]);

    $grant = app(GrantStaffScope::class)->handle($actor, $target, $unit, 'grant');

    expect($grant->id)->toBe($racingId)
        ->and(AuditLogEntry::count())->toBe(0);
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'needs MySQL REPEATABLE READ');
