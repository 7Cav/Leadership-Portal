<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * App\Http\Middleware\TrustProxies: the proxy list comes from `portal.trusted_proxies`, read per
 * request, so a value set in `.env` (loaded into config after bootstrap/app.php's middleware
 * callback has run) or kept in a cached config both apply. phpunit.xml pins TRUSTED_PROXIES
 * empty, so every proxy below reaches the middleware through config alone.
 */
beforeEach(function () {
    Route::get('/__test/client', fn (Request $request) => [
        'ip' => $request->ip(),
        'secure' => $request->secure(),
    ]);
});

function throughProxy(string $remoteAddr)
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->get('/__test/client');
}

it('trusts a proxy listed in config, with the env value empty', function () {
    expect(env('TRUSTED_PROXIES'))->toBeEmpty();
    config()->set('portal.trusted_proxies', '10.0.0.5, 10.1.0.0/16');

    throughProxy('10.0.0.5')->assertOk()->assertExactJson(['ip' => '203.0.113.9', 'secure' => true]);
    throughProxy('10.1.4.4')->assertOk()->assertExactJson(['ip' => '203.0.113.9', 'secure' => true]);
});

it('ignores forwarded headers from an address that is not listed', function () {
    config()->set('portal.trusted_proxies', '10.0.0.5');

    throughProxy('198.51.100.7')->assertOk()->assertExactJson(['ip' => '198.51.100.7', 'secure' => false]);
});

it('trusts nothing when the setting is empty', function () {
    config()->set('portal.trusted_proxies', '');

    throughProxy('10.0.0.5')->assertOk()->assertExactJson(['ip' => '10.0.0.5', 'secure' => false]);
});

it('trusts nothing when the setting is empty, whatever Host the client sends', function () {
    // The framework trusts every address when no proxy is set and the Host ends in .on-forge.com or
    // .on-vapor.com. The client picks the Host, so that would let it forge its own address.
    config()->set('portal.trusted_proxies', '');

    foreach (['portal.on-forge.com', 'portal.on-vapor.com'] as $host) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->get("http://{$host}/__test/client")
            ->assertOk()->assertExactJson(['ip' => '198.51.100.7', 'secure' => false]);
    }
});

it('sends HSTS in production when TLS ends at a trusted proxy', function () {
    config()->set('portal.trusted_proxies', '10.0.0.5');
    app()['env'] = 'production';

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('http://localhost/login')
        ->assertOk();

    expect($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000');
});
