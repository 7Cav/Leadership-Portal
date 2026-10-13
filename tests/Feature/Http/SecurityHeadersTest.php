<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * SetSecurityHeaders (NFR5): anti-framing, no-sniff and referrer policy on every response, HSTS
 * only where it is safe to pin, and never at the expense of a header a route set itself.
 */
function expectBaselineSecurityHeaders($response): void
{
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'");
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    expect($response->headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(), geolocation=()');
    expect($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin');
}

it('sends the baseline headers on a guest page', function () {
    $response = $this->get('/login')->assertOk();

    expectBaselineSecurityHeaders($response);
});

it('sends them on an authenticated page', function () {
    $response = $this->actingAs(User::factory()->create())->get('/home')->assertOk();

    expectBaselineSecurityHeaders($response);
});

it('sends them on responses the web group never produces: an unknown path and a CSRF rejection', function () {
    // Global middleware, not `web`: a 404 for an unmatched path never enters the group, and a
    // 419 is rendered by the CSRF middleware before the route runs.
    expectBaselineSecurityHeaders($this->get('/no-such-page')->assertNotFound());

    liftCsrfTestShortCircuit();
    expectBaselineSecurityHeaders($this->actingAs(User::factory()->create())->post('/logout')->assertStatus(419));
});

it('leaves a header alone when the response already set it', function () {
    Route::get('/__test/embeddable', fn () => response('ok')
        ->header('X-Frame-Options', 'SAMEORIGIN')
        ->header('Content-Security-Policy', "frame-ancestors 'self'"));

    $response = $this->get('/__test/embeddable')->assertOk();

    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
    expect($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'self'");
    // The ones it did not set still arrive.
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('sends HSTS in production over HTTPS', function () {
    app()['env'] = 'production';

    $response = $this->get('https://localhost/login')->assertOk();

    expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000');
});

it('withholds HSTS in production over plain HTTP, where browsers ignore it anyway', function () {
    app()['env'] = 'production';

    expect($this->get('http://localhost/login')->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('withholds HSTS outside production even over HTTPS, so no dev host gets pinned for a year', function () {
    expect($this->get('https://localhost/login')->headers->has('Strict-Transport-Security'))->toBeFalse();
});
