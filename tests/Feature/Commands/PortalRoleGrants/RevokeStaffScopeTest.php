<?php

use App\Authorization\ScopeResolver;
use App\Commands\PortalRoleGrants\GrantStaffScope;
use App\Commands\PortalRoleGrants\RevokeStaffScope;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit\ReasonRequiredException;

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
