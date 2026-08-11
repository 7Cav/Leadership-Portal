<?php

use App\Commands\PortalRoleGrants\GrantStaffScope;
use App\Commands\PortalRoleGrants\GrantTargetIsAdminException;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit\ReasonRequiredException;

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

it('is idempotent on re-granting an already-held node (AD-8 — never a duplicate row)', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    app(GrantStaffScope::class)->handle($actor, $target, $unit, 'first grant');
    app(GrantStaffScope::class)->handle($actor, $target, $unit, 'second grant');

    expect(PortalRoleGrant::count())->toBe(1);
});
