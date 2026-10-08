<?php

use App\Models\AuditLogEntry;
use App\Models\User;

// Story 2.9 — `php artisan portal:admin {username} [--revoke]` bootstraps the first Admin without
// SQL, through the audited `SetAdminFlag` Command (AD-16).

it('marks an existing user as Admin, audited', function () {
    $user = User::factory()->create(['username' => 'Jarvis.A']);

    $this->artisan('portal:admin', ['username' => 'Jarvis.A'])
        ->expectsOutput('Jarvis.A is now an Admin.')
        ->assertSuccessful();

    $entry = AuditLogEntry::query()->sole();
    expect($user->fresh()->is_admin)->toBeTrue()
        ->and($entry->action)->toBe('user.admin_granted')
        ->and($entry->actor_id)->toBe($user->id)
        ->and($entry->target_type)->toBe('User')
        ->and($entry->target_id)->toBe((string) $user->id)
        ->and($entry->reason)->toBe('Set from the console: php artisan portal:admin');
});

it('clears the flag with --revoke, audited', function () {
    $user = User::factory()->admin()->create(['username' => 'Jarvis.A']);

    $this->artisan('portal:admin', ['username' => 'Jarvis.A', '--revoke' => true])
        ->expectsOutput('Jarvis.A is no longer an Admin.')
        ->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeFalse()
        ->and(AuditLogEntry::query()->sole()->action)->toBe('user.admin_revoked')
        ->and(AuditLogEntry::query()->sole()->reason)->toBe('Set from the console: php artisan portal:admin --revoke');
});

it('is a no-op, unaudited, when the user already holds the requested state', function () {
    User::factory()->admin()->create(['username' => 'Jarvis.A']);
    User::factory()->create(['username' => 'Smith.B']);

    $this->artisan('portal:admin', ['username' => 'Jarvis.A'])
        ->expectsOutput('Jarvis.A is already an Admin; nothing changed.')
        ->assertSuccessful();
    $this->artisan('portal:admin', ['username' => 'Smith.B', '--revoke' => true])
        ->expectsOutput('Smith.B is already not an Admin; nothing changed.')
        ->assertSuccessful();

    expect(AuditLogEntry::count())->toBe(0);
});

it('fails for an unknown username', function () {
    $this->artisan('portal:admin', ['username' => 'Nobody.X'])
        ->expectsOutputToContain("No portal user is named 'Nobody.X'")
        ->assertFailed();

    expect(AuditLogEntry::count())->toBe(0);
});

it('refuses to guess between two users sharing a username', function () {
    User::factory()->count(2)->create(['username' => 'Jarvis.A']);

    $this->artisan('portal:admin', ['username' => 'Jarvis.A'])
        ->expectsOutputToContain('2 portal users are named')
        ->assertFailed();

    expect(User::query()->where('is_admin', true)->count())->toBe(0)
        ->and(AuditLogEntry::count())->toBe(0);
});
