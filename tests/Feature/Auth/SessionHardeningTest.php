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
    // `secure` stays env-overridable because local dev runs over http, where a Secure cookie
    // is never sent at all. Assert the setting actually reaches the cookie.
    config()->set('session.secure', true);

    expect(sessionCookie($this->get('/login'))->isSecure())->toBeTrue();
});

it('never leaves the Secure flag unset by default (AC #3)', function () {
    // The framework ships a bare `env('SESSION_SECURE_COOKIE')`, which is *null* when the key
    // is absent — so a deploy that simply forgets it gets a session cookie with no Secure
    // attribute and nothing anywhere complains. The shipped default must resolve to an
    // explicit boolean (true under APP_ENV=production, false for local http) so the decision
    // is always made rather than defaulted into a hole.
    $shipped = withoutEnv('SESSION_SECURE_COOKIE', fn () => require config_path('session.php'));

    expect($shipped['secure'])->toBeBool();
});

it('ships a 24h session window as the default (AC #4)', function () {
    // Proposal §10.2's 24h lifetime, applied to the portal session — the one long-lived
    // credential the portal actually holds. See the story artifact: the forum OAuth access
    // token is handshake-scoped and discarded, so there is no token lifetime to set here.
    //
    // This asserts the *shipped* default, not the resolved value: a contributor's local .env
    // may carry any lifetime, so `config('session.lifetime')` would test their machine. What
    // the repo must guarantee is that an environment setting nothing still gets 24h.
    $shipped = withoutEnv(
        'SESSION_LIFETIME',
        fn () => withoutEnv('SESSION_EXPIRE_ON_CLOSE', fn () => require config_path('session.php')),
    );

    expect($shipped['lifetime'])->toBe(1440);
    // Also the shipped default rather than resolved config: a window is meaningless if the
    // session dies with the browser instead.
    expect($shipped['expire_on_close'])->toBeFalse();
});

it('expires the session cookie on the configured window rather than on browser close (AC #4)', function () {
    $cookie = sessionCookie($this->get('/login'));

    // A session cookie with no expiry would die with the browser and outlive nothing —
    // the window has to be carried on the cookie for the lifetime to mean anything.
    expect($cookie->getExpiresTime())
        ->toBeGreaterThan(now()->addMinutes(config('session.lifetime') - 1)->getTimestamp());
});

it('redirects a genuinely aged-out session to login with a 302, never a 500 (AC #4)', function () {
    // Really age a session out rather than send an empty one: sign in for real against a
    // persistent driver, then travel past the window so the handler treats the record as
    // expired on read. Sending a session that merely lacks an auth key would be
    // indistinguishable from an anonymous request and would pass with the lifetime deleted.
    config(['session.driver' => 'database']);

    $sessionId = logInThroughTheForum();
    $this->withCookie(config('session.cookie'), $sessionId);

    $this->get('/home')->assertOk();

    $this->travel(config('session.lifetime') + 1)->minutes();
    $this->flushSession();
    $this->app['auth']->forgetGuards();

    $this->get('/home')
        ->assertStatus(302)
        ->assertRedirect(route('login'));

    $this->assertGuest();
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

    // Guard the guard: a redirect to login is also what an unprotected logout returns, so
    // without this the test would pass even if the short-circuit lift silently stopped
    // working — unlike its 419 siblings, which can only come from real validation.
    expect(app()->runningUnitTests())->toBeFalse();

    $this->actingAs($user)->get('/home');

    $this->post('/logout', ['_token' => session()->token()])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('admits a same-origin POST on the Sec-Fetch-Site header alone (AC #5, as the framework enforces it)', function () {
    $user = User::factory()->create();

    liftCsrfTestShortCircuit();

    // Documents the path production traffic actually takes, which is NOT the token path.
    // Laravel 13's PreventRequestForgery checks `hasValidOrigin()` BEFORE `tokensMatch()`,
    // so a browser sending `Sec-Fetch-Site: same-origin` is admitted with no token at all.
    // That is sound — the header is browser-set and cannot be forged cross-site — but it
    // makes AC #5's literal "all state-changing requests require a valid CSRF token" false,
    // and the 419 tests above only see rejection because they omit the header. Pinned here
    // so flipping `$originOnly`/`$allowSameSite` can't change the contract unnoticed.
    $this->actingAs($user)
        ->withHeader('Sec-Fetch-Site', 'same-origin')
        ->post('/logout')
        ->assertRedirect(route('login'));
});

it('rejects a cross-site POST with no token — the attack AC #5 is about (AC #5)', function () {
    $user = User::factory()->create();

    liftCsrfTestShortCircuit();

    $this->actingAs($user)
        ->withHeader('Sec-Fetch-Site', 'cross-site')
        ->post('/logout')
        ->assertStatus(419);
});
