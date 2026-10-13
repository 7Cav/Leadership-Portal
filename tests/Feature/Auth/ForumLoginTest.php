<?php

use App\Integration\Forum\Requests\ExchangeCodeRequest;
use App\Integration\Forum\Requests\FetchProfileRequest;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/**
 * Fake the two forum HTTP calls the login handshake makes. The AD-3 seam (a Saloon
 * connector reached only through Integration) is exactly what lets us drive the whole
 * flow without a live 7cav.us — no HTTP leaves the test. `tokenResponse`/`profileResponse`
 * accept a raw MockResponse or a throwing closure to simulate malformed/transport failures.
 */
function mockForum(
    int $userId = 4,
    string $username = 'Gryspeert.J',
    ?string $email = 'j@7cav.us',
    int $tokenStatus = 200,
    int $profileStatus = 200,
    mixed $tokenResponse = null,
    mixed $profileResponse = null,
): void {
    MockClient::destroyGlobal();
    MockClient::global([
        ExchangeCodeRequest::class => $tokenResponse ?? MockResponse::make(
            ['access_token' => 'tok-abc', 'token_type' => 'Bearer', 'expires_in' => 3600],
            $tokenStatus,
        ),
        FetchProfileRequest::class => $profileResponse ?? MockResponse::make(
            ['me' => ['user_id' => $userId, 'username' => $username, 'email' => $email]],
            $profileStatus,
        ),
        // A successful callback also enriches from the Cav roster (Story 1.6). These tests are
        // about the forum handshake, so the roster answers empty: enrichment degrades, and only a
        // user the roster cache already knows as serving gets a session (the sign-in gate is
        // ServingMemberSignInTest's). Without a mock here Saloon throws NoMockResponseFound and
        // the callback 500s — which is itself the useful signal that the login path calls Cav.
        'api.7cav.us/*' => MockResponse::make(['profiles' => []]),
    ]);
}

beforeEach(function () {
    // Env carries these in real environments; the bare test env has no .env, so seed
    // a complete client config (the typed requests require non-null credentials).
    config()->set('services.sevencav', [
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
        'redirect_uri' => 'http://localhost/oauth/callback/7cav',
        'base_url' => 'https://7cav.us',
        'authorize_url' => 'https://7cav.us/oauth2/authorize',
        'scopes' => ['user:read'],
    ]);
});

afterEach(fn () => MockClient::destroyGlobal());

it('shows a single "Continue with 7Cav" login screen to guests', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Login'));
});

it('redirect action stashes a state nonce and sends the browser to the forum authorize URL (AC #1)', function () {
    $response = $this->get('/auth/redirect');

    $state = session('forum_oauth_state');
    expect($state)->toBeString()->not->toBeEmpty();

    $response->assertRedirect();
    $target = $response->headers->get('Location');
    expect($target)->toStartWith('https://7cav.us/oauth2/authorize?');
    expect($target)->toContain('response_type=code');
    expect($target)->toContain('state='.$state);
    // AC #1: read-only scope only — a regression widening this (e.g. user:write) must fail here.
    expect($target)->toContain('scope=user%3Aread');
    expect($target)->toContain('client_id=test-client');
    expect($target)->toContain('redirect_uri=');
});

it('establishes an authenticated session, regenerates the session id, and lands home (AC #2)', function () {
    // A returning trooper linked to a serving milpac: the sign-in gate admits them from the local
    // roster cache, so the empty Cav world below does not get in the way of the handshake.
    User::factory()->serving()->create(['forum_user_id' => 4]);
    mockForum();

    $this->withSession(['forum_oauth_state' => 'valid-state']);
    $idBefore = session()->getId();

    $response = $this->get('/oauth/callback/7cav?code=good-code&state=valid-state');

    $response->assertRedirect(route('home'));
    $this->assertAuthenticated();
    expect(session()->getId())->not->toBe($idBefore); // session()->regenerate() ran (NFR5)
    $this->assertDatabaseHas('users', ['forum_user_id' => 4, 'username' => 'Gryspeert.J']);
});

it('rejects a state mismatch (callback CSRF) with no partial session (AC #5)', function () {
    mockForum();

    $response = $this->withSession(['forum_oauth_state' => 'expected'])
        ->get('/oauth/callback/7cav?code=good-code&state=attacker-supplied');

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('rejects a denied/errored consent with no partial session (AC #5)', function () {
    mockForum();

    $response = $this->withSession(['forum_oauth_state' => 'valid-state'])
        ->get('/oauth/callback/7cav?error=access_denied&state=valid-state');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('handles a failed token exchange gracefully — back to login, never a 500 (AC #5)', function () {
    mockForum(tokenStatus: 400);

    $response = $this->withSession(['forum_oauth_state' => 'valid-state'])
        ->get('/oauth/callback/7cav?code=bad-code&state=valid-state');

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('handles a failed profile fetch after a good token exchange — no partial session (AC #5)', function () {
    // Token exchange succeeds (a live token now exists in memory), profile fetch then 401s.
    mockForum(profileStatus: 401);

    $response = $this->withSession(['forum_oauth_state' => 'valid-state'])
        ->get('/oauth/callback/7cav?code=good-code&state=valid-state');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('handles a malformed token payload (200 but no access_token) — never a 500 (AC #5)', function () {
    mockForum(tokenResponse: MockResponse::make(['token_type' => 'Bearer'], 200));

    $response = $this->withSession(['forum_oauth_state' => 'valid-state'])
        ->get('/oauth/callback/7cav?code=good-code&state=valid-state');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('handles a malformed profile payload (200 but no user_id) — never a 500 (AC #5)', function () {
    mockForum(profileResponse: MockResponse::make(['me' => ['username' => 'NoId']], 200));

    $response = $this->withSession(['forum_oauth_state' => 'valid-state'])
        ->get('/oauth/callback/7cav?code=good-code&state=valid-state');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('handles a non-JSON 2xx body (proxy/WAF HTML) — never a 500 (AC #5)', function () {
    mockForum(tokenResponse: MockResponse::make('<html>502 Bad Gateway</html>', 200));

    $response = $this->withSession(['forum_oauth_state' => 'valid-state'])
        ->get('/oauth/callback/7cav?code=good-code&state=valid-state');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('handles the forum being unreachable (connection failure) — never a 500 (AC #5)', function () {
    // Simulate a transport-level failure (timeout/DNS/refused): Saloon throws
    // FatalRequestException from send(), which must still funnel to the graceful redirect.
    mockForum(tokenResponse: fn (PendingRequest $pending) => throw new FatalRequestException(
        new Exception('Connection refused'),
        $pending,
    ));

    $response = $this->withSession(['forum_oauth_state' => 'valid-state'])
        ->get('/oauth/callback/7cav?code=good-code&state=valid-state');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('rejects a callback with neither code nor error — no session (AC #5)', function () {
    $response = $this->withSession(['forum_oauth_state' => 'valid-state'])
        ->get('/oauth/callback/7cav?state=valid-state');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('updates the same identity row on re-login instead of duplicating (AC #7)', function () {
    mockForum(userId: 4, username: 'Old.Name');
    $this->withSession(['forum_oauth_state' => 's1'])->get('/oauth/callback/7cav?code=c1&state=s1');

    mockForum(userId: 4, username: 'New.Name');
    $this->withSession(['forum_oauth_state' => 's2'])->get('/oauth/callback/7cav?code=c2&state=s2');

    expect(User::count())->toBe(1);
    expect(User::first()->username)->toBe('New.Name');
});

it('stores no password anywhere — SSO-only identity (AC #4, #7)', function () {
    mockForum();
    $this->withSession(['forum_oauth_state' => 's'])->get('/oauth/callback/7cav?code=c&state=s');

    expect(Schema::hasColumn('users', 'password'))->toBeFalse();
    expect(Schema::hasColumn('users', 'remember_token'))->toBeFalse();
    expect(Schema::hasTable('password_reset_tokens'))->toBeFalse();
    expect(User::first()->getAttributes())->not->toHaveKey('password');
});

it('guards the authenticated landing — unauthenticated hits redirect to login (AC #2)', function () {
    $this->get('/home')->assertRedirect(route('login'));
});

it('throttles starting the handshake: the 11th redirect from one IP in a minute goes back to the login screen', function () {
    // Every hit mints a state nonce and writes it to the session store, with no session needed.
    for ($i = 1; $i <= 10; $i++) {
        $this->get('/auth/redirect')->assertRedirect();
    }

    $this->get('/auth/redirect')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Too many sign-in attempts. Try again in a minute.');

    // Another client is unaffected: the budget is per IP.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/auth/redirect')->assertRedirect();
});

it('throttles the callback too, on a budget of its own', function () {
    // Each callback can spend a forum token exchange. A cancelled consent is the cheapest
    // callback to replay, and it must still count.
    for ($i = 1; $i <= 10; $i++) {
        $this->get('/oauth/callback/7cav?error=access_denied')->assertRedirect(route('login'));
    }

    $this->get('/oauth/callback/7cav?error=access_denied')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Too many sign-in attempts. Try again in a minute.');

    // Spending the callback budget leaves starting a fresh sign-in alone: one real sign-in is
    // one hit on each route, so they are counted apart.
    $this->get('/auth/redirect')->assertRedirect();
});

it('sets the session cookie HttpOnly + SameSite=Lax (AC #6)', function () {
    $response = $this->get('/login');

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect(strtolower((string) $cookie->getSameSite()))->toBe('lax');
});
