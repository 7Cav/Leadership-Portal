<?php

use App\Models\User;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Route;

/**
 * SetSecurityHeaders (NFR5): anti-framing, no-sniff and referrer policy on every response, HSTS
 * only where it is safe to pin, the content security policy (#485), and never at the expense of a
 * header a route set itself.
 */
function expectBaselineSecurityHeaders($response): void
{
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($response->headers->get('Content-Security-Policy'))->toBe(expectedCspPolicy(cspNonceOf($response)));
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    expect($response->headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(), geolocation=()');
    expect($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin');
    expect($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin');
}

/** The policy config/csp.php gives, with a response's nonce and any extra sources a directive gains. */
function expectedCspPolicy(string $nonce, array $extra = []): string
{
    $directives = [
        'default-src' => "'self'",
        'script-src' => "'self' 'nonce-{$nonce}'",
        'style-src' => "'self' 'unsafe-inline'",
        'img-src' => "'self' data: https://7cav.us https://*.7cav.us",
        'font-src' => "'self'",
        'connect-src' => "'self'",
        'object-src' => "'none'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'none'",
    ];

    return collect($directives)
        ->map(fn (string $sources, string $name) => trim("{$name} {$sources} ".($extra[$name] ?? '')))
        ->implode('; ');
}

/** The nonce in a response's policy header. */
function cspNonceOf($response, string $header = 'Content-Security-Policy'): string
{
    preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get($header), $match);
    expect($match)->toHaveCount(2);

    return $match[1];
}

it('sends the baseline headers on a guest page', function () {
    $response = $this->get('/login')->assertOk();

    expectBaselineSecurityHeaders($response);
});

it('sends them on an authenticated page', function () {
    $response = $this->actingAs(User::factory()->serving()->create())->get('/home')->assertOk();

    expectBaselineSecurityHeaders($response);
});

it('sends them on responses the web group never produces: an unknown path and a CSRF rejection', function () {
    // Global middleware, not `web`: a 404 for an unmatched path never enters the group, and a
    // 419 is rendered by the CSRF middleware before the route runs.
    expectBaselineSecurityHeaders($this->get('/no-such-page')->assertNotFound());

    liftCsrfTestShortCircuit();
    expectBaselineSecurityHeaders($this->actingAs(User::factory()->serving()->create())->post('/logout')->assertStatus(419));
});

it('sends them on the maintenance page, which a global middleware renders before the app runs', function () {
    config(['app.debug' => false]);
    // Bound rather than written to storage/framework/down, so no other test sees the app as down.
    app()->instance(MaintenanceMode::class, new class implements MaintenanceMode
    {
        public function activate(array $payload): void {}

        public function deactivate(): void {}

        public function active(): bool
        {
            return true;
        }

        public function data(): array
        {
            return ['status' => 503];
        }
    });

    expectBaselineSecurityHeaders($this->get('/login')->assertStatus(503));
});

it('sends them on a 413 for a body over the size limit', function () {
    if ((int) ini_get('post_max_size') === 0) {
        $this->markTestSkipped('post_max_size is unlimited here, so no body is too large.');
    }

    $response = $this->call('POST', '/logout', server: ['CONTENT_LENGTH' => (string) PHP_INT_MAX])->assertStatus(413);

    expectBaselineSecurityHeaders($response);
});

it('sends them on a CORS preflight answered before routing', function () {
    config(['cors.paths' => ['api/*']]);

    $response = $this->call('OPTIONS', '/api/anything', server: [
        'HTTP_ORIGIN' => 'https://example.org',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])->assertNoContent();

    expectBaselineSecurityHeaders($response);
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

it('gives each request its own nonce, the one the inline theme script carries', function () {
    $first = $this->get('/login')->assertOk();
    $second = $this->get('/login')->assertOk();

    expect(cspNonceOf($first))->not->toBe(cspNonceOf($second))
        ->and($first->getContent())->toContain('<script nonce="'.cspNonceOf($first).'">')
        ->and($second->getContent())->toContain('<script nonce="'.cspNonceOf($second).'">');
});

it('puts the nonce on every script tag, those @vite prints included, and keeps the dev server out outside local', function () {
    // Vite's dev server stands in for a build: its tags need no manifest, and a test has none.
    $this->withVite();
    $hot = tempnam(sys_get_temp_dir(), 'vite-hot');
    file_put_contents($hot, 'http://localhost:5173');
    app(Vite::class)->useHotFile($hot);

    $response = $this->get('/login')->assertOk();
    unlink($hot);
    $nonce = cspNonceOf($response);

    preg_match_all('/<script\b[^>]*>/', $response->getContent(), $tags);
    // The page JSON is data the browser never runs, so it needs none.
    $scripts = array_values(array_filter($tags[0], fn (string $tag) => ! str_contains($tag, 'application/json')));

    // The theme script, Vite's client and the app entry at least.
    expect($response->getContent())->toContain('src="http://localhost:5173/@vite/client"')
        ->and(count($scripts))->toBeGreaterThanOrEqual(3);
    foreach ($scripts as $tag) {
        expect($tag)->toContain('nonce="'.$nonce.'"');
    }
    // A hot file outside a local machine opens nothing.
    expect($response->headers->get('Content-Security-Policy'))->toBe(expectedCspPolicy($nonce));
});

it('allows the Vite dev server and its websocket on a local machine while it runs', function () {
    app()['env'] = 'local';
    $this->withVite();
    $hot = tempnam(sys_get_temp_dir(), 'vite-hot');
    file_put_contents($hot, "http://localhost:5173\n");
    app(Vite::class)->useHotFile($hot);

    $response = $this->get('/login')->assertOk();
    unlink($hot);

    $dev = 'http://localhost:5173';
    expect($response->headers->get('Content-Security-Policy'))->toBe(expectedCspPolicy(cspNonceOf($response), [
        'script-src' => $dev,
        'style-src' => $dev,
        'img-src' => $dev,
        'font-src' => $dev,
        'connect-src' => "{$dev} ws://localhost:5173",
    ]));
});

it('keeps a local machine strict when the Vite dev server is not running', function () {
    app()['env'] = 'local';
    $this->withVite();
    app(Vite::class)->useHotFile(sys_get_temp_dir().'/no-such-vite-hot-file');
    // A plain response, so nothing asks Vite for a build a test does not have.
    Route::get('/__test/plain', fn () => response('ok'));

    $response = $this->get('/__test/plain')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe(expectedCspPolicy(cspNonceOf($response)));
});

it('sends the policy as report-only when configured, and still enforces frame-ancestors', function () {
    config(['csp.report_only' => true]);

    $response = $this->get('/login')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'")
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toBe(expectedCspPolicy(cspNonceOf($response, 'Content-Security-Policy-Report-Only')));
});

it('takes another host from config, with no code change', function () {
    config(['csp.directives.img-src' => [...config('csp.directives.img-src'), 'https://images.example.org']]);

    $response = $this->get('/login')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))
        ->toBe(expectedCspPolicy(cspNonceOf($response), ['img-src' => 'https://images.example.org']));
});

it('sends frame-ancestors alone on a debug error page, which draws itself with inline scripts', function () {
    Route::get('/__test/server-error', fn () => response('Server error', 500));

    config(['app.debug' => true]);
    $debug = $this->get('/__test/server-error')->assertStatus(500);
    expect($debug->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'");

    config(['app.debug' => false]);
    $response = $this->get('/__test/server-error')->assertStatus(500);
    expect($response->headers->get('Content-Security-Policy'))->toBe(expectedCspPolicy(cspNonceOf($response)));
});
