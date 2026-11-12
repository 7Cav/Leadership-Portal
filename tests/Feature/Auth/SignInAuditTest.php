<?php

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Sign-ins, refused sign-ins and sign-outs in the audit log, with the address the sign-in started from and the page
 * it was headed to. Forum user 4 signs in; Cav answers with empty rosters, so only an Admin gets in.
 */
function auditSignIn(array $session = []): TestResponse
{
    MockClient::destroyGlobal();
    MockClient::global([
        '7cav.us/api/oauth2/token' => MockResponse::make(['access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        '7cav.us/api/me' => MockResponse::make(['me' => ['user_id' => 4, 'username' => 'Hale.S', 'email' => 'hale@7cav.us']]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => MockResponse::make(['profiles' => []]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_RESERVE/lite' => MockResponse::make(['profiles' => []]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_ELOA/lite' => MockResponse::make(['profiles' => []]),
    ]);

    return test()
        ->withSession(['forum_oauth_state' => 'state-xyz', ...$session])
        ->get('/auth/callback?code=auth-code&state=state-xyz');
}

beforeEach(function () {
    config()->set('services.sevencav', [
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
        'redirect_uri' => 'http://localhost/auth/callback',
        'base_url' => 'https://7cav.us',
        'authorize_url' => 'https://7cav.us/oauth2/authorize',
        'scopes' => ['user:read'],
    ]);
    config()->set('services.cav', ['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']);
    Exceptions::fake();
});

afterEach(fn () => MockClient::destroyGlobal());

it('records a sign-in against the user, with where it started and the page it was headed to', function () {
    $admin = User::factory()->admin()->create(['forum_user_id' => 4, 'username' => 'Hale.S']);

    auditSignIn(['forum_oauth_via' => 'https://portal.example', 'url.intended' => 'https://portal.example/reports'])
        ->assertRedirect('https://portal.example/reports');

    $entry = AuditLogEntry::sole();
    expect($entry->action)->toBe('auth.signed_in')
        ->and($entry->actor_id)->toBe($admin->id)
        ->and($entry->target_type)->toBe('User')
        ->and($entry->target_id)->toBe((string) $admin->id)
        ->and($entry->details['subject'])->toBe('Hale.S')
        ->and(collect($entry->details['changes'])->pluck('field')->all())->toBe(['Via https://portal.example', 'Headed to https://portal.example/reports']);
});

it('remembers where the sign-in started, from the redirect to the forum', function () {
    $this->get('https://portal.example/auth/redirect')->assertRedirect();

    expect(session('forum_oauth_via'))->toBe('https://portal.example');
});

it('names the callback address when the session lost where it started, and no page when none was asked for', function () {
    User::factory()->admin()->create(['forum_user_id' => 4]);

    auditSignIn()->assertRedirect(route('home'));

    expect(collect(AuditLogEntry::sole()->details['changes'])->pluck('field')->all())->toBe(['Via http://localhost']);
});

it('records a refused sign-in with why, and starts no session', function () {
    auditSignIn(['forum_oauth_via' => 'https://portal.example'])->assertRedirect(route('login'));

    $this->assertGuest();
    $entry = AuditLogEntry::sole();
    expect($entry->action)->toBe('auth.refused')
        ->and($entry->actor_id)->toBe(User::firstWhere('forum_user_id', 4)->id)
        ->and(collect($entry->details['changes'])->pluck('field')->all())->toBe(['Refused: not linked to a serving 7Cav milpac', 'Via https://portal.example']);
});

it('records nothing for a handshake that names no user', function () {
    $this->withSession(['forum_oauth_state' => 'state-xyz'])->get('/auth/callback?code=auth-code&state=wrong')->assertRedirect(route('login'));

    expect(AuditLogEntry::count())->toBe(0);
});

it('cuts a very long page address short', function () {
    User::factory()->admin()->create(['forum_user_id' => 4]);

    auditSignIn(['url.intended' => 'http://localhost/'.str_repeat('a', 1000)]);

    $headed = collect(AuditLogEntry::sole()->details['changes'])->pluck('field')->last();
    expect(mb_strlen($headed))->toBeLessThanOrEqual(strlen('Headed to ') + 300);
});

it('records a sign-out against the user', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/login');

    $entry = AuditLogEntry::sole();
    expect($entry->action)->toBe('auth.signed_out')
        ->and($entry->actor_id)->toBe($user->id)
        ->and($entry->target_id)->toBe((string) $user->id);
});
