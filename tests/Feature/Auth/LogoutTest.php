<?php

use App\Models\User;

/**
 * Logout as a security boundary (Story 1.5 AC #1, #2): ending a session must drop the
 * auth state, flush the session data, migrate the session id so the old one cannot be
 * replayed, and rotate the CSRF token — not merely forget who the user was.
 */
it('invalidates the session and rotates the CSRF token on logout (AC #1)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/home')->assertOk();

    $idBefore = session()->getId();
    $tokenBefore = session()->token();

    $this->post('/logout')->assertRedirect(route('login'));

    $this->assertGuest();
    expect(session()->getId())->not->toBe($idBefore);   // session()->invalidate() migrated the id
    expect(session()->token())->not->toBe($tokenBefore); // regenerateToken() rotated the CSRF token
});

it('flushes the session payload, not just the auth state (AC #1)', function () {
    $user = User::factory()->create();

    // A marker standing in for anything a session accumulates while signed in. If logout
    // only called Auth::logout(), this would survive — which is the bug this asserts against.
    $this->actingAs($user)->withSession(['scratch' => 'from-the-authenticated-session'])->get('/home');

    $this->post('/logout');

    expect(session()->has('scratch'))->toBeFalse();
});

it('denies a previously-authenticated request after logout (AC #2)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/home')->assertOk();
    $this->post('/logout');

    $this->get('/home')->assertRedirect(route('login'));
});

it('marks authenticated responses no-store so "back" cannot repaint them (AC #2)', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/home');

    // The session is genuinely dead after logout, but without no-store the browser happily
    // re-paints the last authenticated page from its own cache on a shared machine.
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
