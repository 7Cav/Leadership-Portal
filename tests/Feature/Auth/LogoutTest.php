<?php

use App\Models\User;

/**
 * Logout as a security boundary (Story 1.5 AC #1, #2): ending a session must drop the
 * auth state, flush the session data, migrate the session id so the old one cannot be
 * replayed, and rotate the CSRF token — not merely forget who the user was.
 */
it('rotates the CSRF token on logout (AC #1)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/home')->assertOk();

    $tokenBefore = session()->token();

    $this->post('/logout')->assertRedirect(route('login'));

    $this->assertGuest();
    // A full-strength token, not merely "different": `invalidate()` flushes `_token` on its
    // own, so asserting only inequality would pass against a null token — i.e. it would
    // still pass with `regenerateToken()` deleted, leaving this half of AC #1 ungated.
    expect(session()->token())
        ->toBeString()
        ->toHaveLength(40)
        ->not->toBe($tokenBefore);
});

it('flushes the session payload, not just the auth state (AC #1)', function () {
    $user = User::factory()->create();

    // A marker standing in for anything a session accumulates while signed in. If logout
    // only called Auth::logout(), this would survive — which is the bug this asserts against.
    $this->actingAs($user)->withSession(['scratch' => 'from-the-authenticated-session'])->get('/home');

    $this->post('/logout');

    expect(session()->has('scratch'))->toBeFalse();
});

it('kills the session itself, so replaying the pre-logout cookie is denied (AC #2)', function () {
    // Everything here exists to make the replay representable at all, and each piece is
    // load-bearing:
    //  * a persistent driver — phpunit.xml pins SESSION_DRIVER=array, where nothing survives
    //    a request, so there is no session to replay;
    //  * a real login through the callback rather than actingAs(), because actingAs() only
    //    sets the guard's user in memory and never writes identity into the session — replay
    //    a cookie from an actingAs() session and it was never authenticated in the first place;
    //  * an explicit cookie on every request, because the test client keeps no cookie jar and
    //    would otherwise invent a fresh session id per request, so logout would invalidate a
    //    different session than the one under test.
    // Without all three, this test passes on `Auth::logout()` alone — proving only that the
    // guard forgot the user in-process while the session lived on.
    config(['session.driver' => 'database']);

    $sessionId = logInThroughTheForum();
    $this->withCookie(config('session.cookie'), $sessionId);

    $this->get('/home')->assertOk();

    $this->post('/logout')->assertRedirect(route('login'));

    // The id must move and the old record must be gone from the store — `invalidate()`'s
    // `migrate(true)`, not just a `flush()` that empties the payload in place and leaves the
    // id (and the row) standing. Because the request above genuinely started from
    // `$sessionId` via the cookie, these two assertions can finally see that.
    expect(session()->getId())->not->toBe($sessionId);
    expect((string) session()->getHandler()->read($sessionId))->toBe('');

    // Re-present the exact cookie the authenticated request carried, as a stolen cookie or a
    // stale tab would, with the in-memory session and guard discarded so identity has to be
    // re-resolved from the store. The session behind the cookie must be gone, not just
    // logged out of.
    $this->flushSession();
    $this->app['auth']->forgetGuards();

    $this->get('/home')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('clears the Inertia history so "back" cannot repaint the authenticated page (AC #2)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/home')->assertOk();

    // The header below governs the HTTP cache; this governs the SPA. Pressing back in an
    // Inertia app fires popstate and re-renders the stored page object without issuing any
    // request, so neither no-store nor the dead session gets a say. `clearHistory()` tells
    // the client to discard that stored state, forcing a real request that the auth
    // middleware then bounces. It reaches the client on the very next Inertia response.
    $login = $this->followingRedirects()->post('/logout');

    expect($login->viewData('page'))->toHaveKey('clearHistory');
    expect($login->viewData('page')['clearHistory'])->toBeTrue();
});

it('encrypts the history of an authenticated page, so clearing it on logout has teeth (AC #2)', function (string $path) {
    $user = User::factory()->create();

    // `clearHistory()` above only deletes the client's encryption key. With history
    // encryption off the stored page objects are plaintext, losing the key costs them
    // nothing, and "back" after logout repaints the authenticated page anyway. This pins
    // `history.encrypt` in config/inertia.php: every authenticated page must arrive flagged.
    $page = $this->actingAs($user)->get($path)->assertOk()->viewData('page');

    expect($page)->toHaveKey('encryptHistory');
    expect($page['encryptHistory'])->toBeTrue();
})->with(['/home', '/reports', '/settings/org-structure', '/settings/staff-grants']);

it('marks authenticated responses no-store so a reload cannot repaint them (AC #2)', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/home');

    // The session is genuinely dead after logout, but without no-store the browser happily
    // re-paints the last authenticated page from its own cache on a shared machine.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('Pragma'))->toBe('no-cache');
});

it('marks a rejected request no-store too, not just the happy path (AC #2)', function () {
    $user = User::factory()->create();

    liftCsrfTestShortCircuit();

    // A 419 is produced outside the route, by the CSRF middleware. With APP_DEBUG on that
    // response is a debug page carrying the session payload, so it must not be storable
    // either — which only holds while this middleware wraps the group rather than sitting
    // inside it. Pins the ordering in bootstrap/app.php.
    $response = $this->actingAs($user)->post('/logout');

    $response->assertStatus(419);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('leaves guest responses alone rather than blanket no-store (AC #2)', function () {
    $response = $this->get('/login');

    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('requires an authenticated session to log out', function () {
    $this->post('/logout')->assertRedirect(route('login'));
});

it('is not reachable by GET — a logout link must not be prefetchable', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/logout')->assertStatus(405);
});
