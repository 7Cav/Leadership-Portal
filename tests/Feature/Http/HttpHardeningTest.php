<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Small HTTP edges: production answers only for its own host, the e2e sign-in route refuses
 * outside the loopback server's disposable database, and no path answers cross-origin requests.
 */
afterEach(function () {
    // Trusted hosts are static on the Symfony request, so they would leak into later tests.
    Request::setTrustedHosts([]);
});

it('refuses a request for another host in production instead of redirecting to it', function () {
    config(['app.url' => 'https://portal.7cav.test']);
    app()['env'] = 'production';

    $response = $this->get('http://evil.example/home');

    $response->assertBadRequest();
    expect($response->headers->get('Location'))->toBeNull();
});

it('still answers for the APP_URL host in production', function () {
    config(['app.url' => 'https://portal.7cav.test']);
    app()['env'] = 'production';

    $this->get('https://portal.7cav.test/home')->assertRedirect('https://portal.7cav.test/login');
});

it('refuses the e2e sign-in route without both e2e and its dedicated database, even when a cached route table carries it', function () {
    User::factory()->create(['username' => 'Jarvis.A']);

    // What a `route:cache` built under APP_ENV=e2e would hold: the route, registered.
    app()['env'] = 'e2e';
    Route::middleware('web')->group(base_path('routes/web.php'));
    app()['env'] = 'testing';

    $this->get('/__e2e/login/Jarvis.A')->assertNotFound();
    $this->assertGuest();

    app()['env'] = 'e2e';
    // This suite uses in-memory SQLite. Only Playwright's e2e.sqlite may sign in through this route.
    $this->get('/__e2e/login/Jarvis.A')->assertNotFound();
    $this->assertGuest();
});

it('answers no cross-origin preflight, on api paths included', function () {
    $response = $this->options('/api/anything', [], [
        'Origin' => 'https://evil.example',
        'Access-Control-Request-Method' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});
