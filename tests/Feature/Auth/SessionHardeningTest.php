<?php

use App\Models\User;
use Illuminate\Support\Env;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The session/cookie/CSRF lifecycle spec (Story 1.5 AC #3, #4, #5 — NFR5, proposal §10.2).
 * Story 1.4 asserted the cookie flags on the login screen only; these walk the whole
 * login → use → logout arc and make CSRF rejection a real gate rather than an assumption.
 */
function sessionCookie(TestResponse $response): ?Cookie
{
    return collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
}

it('keeps the session cookie HttpOnly + SameSite=Lax across login, use and logout (AC #3)', function () {
    $user = User::factory()->create();

    $responses = [
        'login screen' => $this->get('/login'),
        'authenticated use' => $this->actingAs($user)->get('/home'),
        'logout' => $this->post('/logout'),
    ];

    foreach ($responses as $stage => $response) {
        $cookie = sessionCookie($response);

        expect($cookie)->not->toBeNull("no session cookie on the $stage response");
        expect($cookie->isHttpOnly())->toBeTrue("session cookie not HttpOnly on $stage");
        expect(strtolower((string) $cookie->getSameSite()))->toBe('lax', "wrong SameSite on $stage");
    }
});

it('marks the session cookie Secure when configured for HTTPS (AC #3)', function () {
    // `secure` is env-driven (SESSION_SECURE_COOKIE) because local dev runs over http and a
    // Secure cookie would simply never be sent. Production sets it true; assert that setting
    // actually reaches the cookie.
    config()->set('session.secure', true);

    expect(sessionCookie($this->get('/login'))->isSecure())->toBeTrue();
});

it('ships a 24h session window as the default (AC #4)', function () {
    // Proposal §10.2's 24h lifetime, applied to the portal session — the one long-lived
    // credential the portal actually holds. See the story artifact: the forum OAuth access
    // token is handshake-scoped and discarded, so there is no token lifetime to set here.
    //
    // This asserts the *shipped* default, not the resolved value: a contributor's local .env
    // may carry any lifetime, so `config('session.lifetime')` would test their machine. What
    // the repo must guarantee is that an environment setting nothing still gets 24h.
    expect(withoutEnv('SESSION_LIFETIME', fn () => (require config_path('session.php'))['lifetime']))
        ->toBe(1440);
    expect(config('session.expire_on_close'))->toBeFalse();
});

it('expires the session cookie on the configured window rather than on browser close (AC #4)', function () {
    $cookie = sessionCookie($this->get('/login'));

    // A session cookie with no expiry would die with the browser and outlive nothing —
    // the window has to be carried on the cookie for the lifetime to mean anything.
    expect($cookie->getExpiresTime())
        ->toBeGreaterThan(now()->addMinutes(config('session.lifetime') - 1)->getTimestamp());
});

it('redirects an expired session to login with a 302, never a 500 (AC #4)', function () {
    // An aged-out session is indistinguishable from no session: the driver holds no auth
    // payload for the cookie's id, so the auth middleware must bounce rather than blow up.
    $this->withSession(['_token' => 'stale-token'])
        ->get('/home')
        ->assertStatus(302)
        ->assertRedirect(route('login'));
});

it('rejects a state-changing POST with no CSRF token, server-side (AC #5)', function () {
    $user = User::factory()->create();

    liftCsrfTestShortCircuit();

    $this->actingAs($user)->post('/logout')->assertStatus(419);
});

it('rejects a state-changing POST with a wrong CSRF token (AC #5)', function () {
    $user = User::factory()->create();

    liftCsrfTestShortCircuit();

    $this->actingAs($user)->post('/logout', ['_token' => 'not-the-session-token'])->assertStatus(419);
});

it('accepts a state-changing POST carrying the session CSRF token (AC #5)', function () {
    $user = User::factory()->create();

    liftCsrfTestShortCircuit();

    $this->actingAs($user)->get('/home');

    $this->post('/logout', ['_token' => session()->token()])->assertRedirect(route('login'));
});

/**
 * `ValidateCsrfToken::handle` short-circuits on `$app->runningUnitTests()`, so a plain
 * feature-test POST never exercises CSRF at all — a test written without lifting that
 * would pass while proving nothing. Moving the app off the `testing` environment makes the
 * middleware validate for real, which is the only way AC #5 becomes a gate.
 */
function liftCsrfTestShortCircuit(): void
{
    app()['env'] = 'production';
}

/**
 * Run `$probe` with an environment variable hidden, so a config file can be re-evaluated as
 * an environment that never sets the key would see it. Restored afterwards either way.
 */
function withoutEnv(string $key, Closure $probe): mixed
{
    $repository = Env::getRepository();
    $original = $repository->get($key);
    $repository->clear($key);

    try {
        return $probe();
    } finally {
        if ($original !== null) {
            $repository->set($key, $original);
        }
    }
}
