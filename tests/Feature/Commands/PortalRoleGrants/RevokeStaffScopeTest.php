<?php

use App\Authorization\ScopeResolver;
use App\Commands\PortalRoleGrants\GrantStaffScope;
use App\Commands\PortalRoleGrants\RevokeStaffScope;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use App\Repositories\PortalRoleGrantRepository\Eloquent as EloquentPortalRoleGrantRepository;
use App\Support\Audit\ReasonRequiredException;
use Illuminate\Support\Facades\DB;

it('revokes an existing grant (AC #2)', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    app(GrantStaffScope::class)->handle($actor, $target, $unit, 'grant');

    app(RevokeStaffScope::class)->handle($actor, $target, $unit, 'no longer staff');

    expect(PortalRoleGrant::count())->toBe(0);
});

it('records exactly one audit_log entry for the revoke (AC #3)', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    $grant = app(GrantStaffScope::class)->handle($actor, $target, $unit, 'grant');

    app(RevokeStaffScope::class)->handle($actor, $target, $unit, 'no longer staff');

    expect(AuditLogEntry::count())->toBe(2); // one for the grant, one for the revoke

    $entry = AuditLogEntry::latest('id')->first();
    expect($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('portal_role_grant.revoked')
        ->and($entry->target_type)->toBe('PortalRoleGrant')
        ->and($entry->target_id)->toBe((string) $grant->id)
        ->and($entry->reason)->toBe('no longer staff');
});

it('refuses a revoke with no reason (AD-16)', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    app(GrantStaffScope::class)->handle($actor, $target, $unit, 'grant');

    expect(fn () => app(RevokeStaffScope::class)->handle($actor, $target, $unit, null))
        ->toThrow(ReasonRequiredException::class);

    expect(PortalRoleGrant::count())->toBe(1);
});

it('is a silent no-op when no grant exists, logging nothing (no work, no log entry)', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    $result = app(RevokeStaffScope::class)->handle($actor, $target, $unit, 'no longer staff');

    expect($result)->toBeNull()
        ->and(AuditLogEntry::count())->toBe(0);
});

it('reflects the revoke in the target\'s effective scope on the very next resolution (AC #2, no cache to invalidate)', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->serving()->create();
    $unit = Unit::factory()->create();
    app(GrantStaffScope::class)->handle($actor, $target, $unit, 'grant');

    expect(app(ScopeResolver::class)->resolve($target))->toContain($unit->id);

    app(RevokeStaffScope::class)->handle($actor, $target, $unit, 'no longer staff');

    expect(app(ScopeResolver::class)->resolve($target))->not->toContain($unit->id);
});

/** The real repository, except that `find()` still answers what a racing request saw before the other one committed. */
function revokeRepositorySeeing(?PortalRoleGrant $seen): PortalRoleGrantRepository
{
    return Mockery::mock(EloquentPortalRoleGrantRepository::class)->makePartial()
        ->shouldReceive('find')->andReturn($seen)->getMock();
}

it('is a silent no-op when a racing revoke removed the grant after the no-op check', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    $stale = PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);
    PortalRoleGrant::query()->whereKey($stale->id)->delete();
    app()->instance(PortalRoleGrantRepository::class, revokeRepositorySeeing($stale));

    $result = app(RevokeStaffScope::class)->handle($actor, $target, $unit, 'no longer staff');

    expect($result)->toBeNull()
        ->and(AuditLogEntry::count())->toBe(0);
});

/** Remove what a MySQL race test committed through the second connection. */
function revokeRaceCleanup(int $unitId, array $userIds): void
{
    DB::connection('race')->table('portal_role_grants')->where('unit_id', $unitId)->delete();
    DB::connection('race')->table('units')->where('id', $unitId)->delete();
    DB::connection('race')->table('users')->whereIn('id', $userIds)->delete();
}

// Under MySQL REPEATABLE READ the losing revoke's snapshot still showed the grant, so it deleted
// nothing and still recorded a second `portal_role_grant.revoked`.
it('records no second revoke when a racing revoke committed after this transaction\'s snapshot (MySQL)', function () {
    config(['database.connections.race' => config('database.connections.mysql')]);
    $actor = User::factory()->admin()->connection('race')->create();
    $target = User::factory()->connection('race')->create();
    $unit = Unit::factory()->connection('race')->create();
    // Committed rows: removed once the test's own transaction has rolled back and let go of its locks.
    $this->beforeApplicationDestroyed(fn () => revokeRaceCleanup($unit->id, [$actor->id, $target->id]));
    $grantId = DB::connection('race')->table('portal_role_grants')->insertGetId(['user_id' => $target->id, 'unit_id' => $unit->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(PortalRoleGrant::query()->count())->toBe(1); // this transaction's snapshot starts here
    DB::connection('race')->table('portal_role_grants')->where('id', $grantId)->delete();

    $result = app(RevokeStaffScope::class)->handle($actor, $target, $unit, 'no longer staff');

    expect($result)->toBeNull()
        ->and(AuditLogEntry::count())->toBe(0);
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'needs MySQL REPEATABLE READ');
