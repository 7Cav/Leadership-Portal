<?php

use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

// Story 2.9 — `php artisan portal:admin {username} [--revoke]` (or `--forum-id=`) bootstraps the first
// Admin without SQL, through the audited `SetAdminFlag` Command (AD-16).

it('marks an existing user as Admin once the match is confirmed, audited', function () {
    $user = User::factory()->serving()->create(['username' => 'Jarvis.A']);

    $this->artisan('portal:admin', ['username' => 'Jarvis.A'])
        ->expectsOutputToContain("Matched Jarvis.A, forum id {$user->forum_user_id}: Cav profile ".Trooper::MILPAC_PROFILE_URL.$user->cav_id)
        ->expectsConfirmation('Make Jarvis.A an Admin?', 'yes')
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

it('changes nothing when the match is not confirmed', function () {
    $user = User::factory()->serving()->create(['username' => 'Jarvis.A']);

    $this->artisan('portal:admin', ['username' => 'Jarvis.A'])
        ->expectsConfirmation('Make Jarvis.A an Admin?', 'no')
        ->expectsOutputToContain('Nothing changed.')
        ->assertFailed();

    expect($user->fresh()->is_admin)->toBeFalse()
        ->and(AuditLogEntry::count())->toBe(0);
});

it('grants by forum user id without a prompt, for scripts', function () {
    $user = User::factory()->serving()->create(['username' => 'Jarvis.A', 'forum_user_id' => 4321]);
    User::factory()->serving()->create(['username' => 'Jarvis.A2', 'forum_user_id' => 1234]);

    $this->artisan('portal:admin', ['--forum-id' => '4321'])
        ->expectsOutputToContain('Matched Jarvis.A, forum id 4321')
        ->expectsOutput('Jarvis.A is now an Admin.')
        ->assertSuccessful();

    expect(User::query()->where('is_admin', true)->pluck('id')->all())->toBe([$user->id]);
});

it('shows the roster status of the match', function () {
    $departed = Trooper::factory()->create(['status' => TrooperStatus::Inactive]);
    User::factory()->create(['username' => 'Doyle.K', 'forum_user_id' => 77, 'cav_id' => $departed->cav_id]);

    $this->artisan('portal:admin', ['--forum-id' => '77'])
        ->expectsOutputToContain('roster status inactive (not serving)')
        ->assertSuccessful();
});

it('refuses to grant Admin to an account with no Cav profile unless forced', function () {
    $user = User::factory()->create(['username' => 'Outsider.Q', 'forum_user_id' => 900, 'cav_id' => null]);

    $this->artisan('portal:admin', ['--forum-id' => '900'])
        ->expectsOutputToContain('forum id 900: no Cav profile linked.')
        ->expectsOutputToContain('Re-run with --force')
        ->assertFailed();

    expect($user->fresh()->is_admin)->toBeFalse()
        ->and(AuditLogEntry::count())->toBe(0);

    $this->artisan('portal:admin', ['--forum-id' => '900', '--force' => true])
        ->expectsOutput('Outsider.Q is now an Admin.')
        ->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();
});

it('does not grant a user whose name only matches by case or accent', function () {
    // MySQL's utf8mb4_unicode_ci makes `where username = 'jarvis.a'` return Jarvis.A; SQLite would not,
    // so the repository answers here the way the production collation does.
    $intended = User::factory()->serving()->create(['username' => 'Jarvis.A', 'forum_user_id' => 12]);
    $this->mock(UserRepository::class, fn ($mock) => $mock->shouldReceive('withUsername')->with('jarvis.a')->andReturn(new EloquentCollection([$intended])));

    $this->artisan('portal:admin', ['username' => 'jarvis.a'])
        ->expectsOutputToContain("No portal user is named 'jarvis.a'. They must sign in once before they can be made Admin. Not an exact match: Jarvis.A (forum id 12).")
        ->assertFailed();

    expect($intended->fresh()->is_admin)->toBeFalse();
});

it('clears the flag with --revoke, including on an Admin with no Cav profile, audited', function () {
    $user = User::factory()->admin()->create(['username' => 'Jarvis.A', 'cav_id' => null]);

    $this->artisan('portal:admin', ['username' => 'Jarvis.A', '--revoke' => true])
        ->expectsConfirmation('Remove Admin from Jarvis.A?', 'yes')
        ->expectsOutput('Jarvis.A is no longer an Admin.')
        ->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeFalse()
        ->and(AuditLogEntry::query()->sole()->action)->toBe('user.admin_revoked')
        ->and(AuditLogEntry::query()->sole()->reason)->toBe('Set from the console: php artisan portal:admin --revoke');
});

it('is a no-op, unaudited and unprompted, when the user already holds the requested state', function () {
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

it('fails for an unknown username or forum id', function () {
    $this->artisan('portal:admin', ['username' => 'Nobody.X'])
        ->expectsOutputToContain("No portal user is named 'Nobody.X'")
        ->assertFailed();
    $this->artisan('portal:admin', ['--forum-id' => '999'])
        ->expectsOutputToContain("No portal user has forum id '999'")
        ->assertFailed();

    expect(AuditLogEntry::count())->toBe(0);
});

it('needs exactly one of a username and --forum-id', function () {
    $this->artisan('portal:admin')->expectsOutputToContain('one of the two')->assertFailed();
    $this->artisan('portal:admin', ['username' => 'Jarvis.A', '--forum-id' => '1'])->expectsOutputToContain('one of the two')->assertFailed();
});

it('refuses to guess between two users sharing a username', function () {
    User::factory()->count(2)->serving()->create(['username' => 'Jarvis.A']);

    $this->artisan('portal:admin', ['username' => 'Jarvis.A'])
        ->expectsOutputToContain('2 portal users are named')
        ->assertFailed();

    expect(User::query()->where('is_admin', true)->count())->toBe(0)
        ->and(AuditLogEntry::count())->toBe(0);
});
