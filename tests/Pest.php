<?php

use App\Integration\Forum\Requests\ExchangeCodeRequest;
use App\Integration\Forum\Requests\FetchProfileRequest;
use App\Models\Trooper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    // Enabled in Story 1.4 — the login flow is the first DB-touching feature (identity
    // upsert + DB sessions). phpunit.xml runs on in-memory SQLite, so no MySQL service
    // is required for the suite.
    ->use(RefreshDatabase::class)
    // Feature tests render the real Blade layout, which calls @vite. The backend CI job is
    // deliberately PHP-only, so there is no built manifest there — stub Vite out rather than
    // make a PHP gate depend on the front-end toolchain. Drop this if a test ever needs to
    // assert on real asset URLs.
    ->beforeEach(fn () => $this->withoutVite())
    // A global Saloon mock client outlives the test that installed it, so drop it between
    // tests rather than relying on every file that fakes the forum to remember (Story 1.5).
    ->afterEach(fn () => MockClient::destroyGlobal())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

// `toBe` for a decoded JSON column, minus object key order. MySQL's JSON type stores an
// object's keys in its own order (shortest first), so `{"trooper_id":7,"reason":"sick"}` reads
// back as `{"reason":"sick","trooper_id":7}`; SQLite keeps the text as written. Values stay
// strict and a list's order still counts: only string-keyed arrays are key-sorted.
expect()->extend('toBeIgnoringKeyOrder', function (mixed $expected) {
    $sortKeys = function (mixed $value) use (&$sortKeys): mixed {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map($sortKeys, $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    };

    expect($sortKeys($this->value))->toBe($sortKeys($expected));

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Make CSRF verification real for the current test (Story 1.5).
 *
 * `PreventRequestForgery::handle` short-circuits on `$app->runningUnitTests()`, so a plain
 * feature-test POST never exercises CSRF at all — a test written without lifting this would
 * pass while proving nothing. Moving the app off the `testing` environment makes the
 * middleware validate for real.
 */
function liftCsrfTestShortCircuit(): void
{
    app()['env'] = 'production';
}

/**
 * Run `$probe` with an environment variable hidden, so a config file can be re-evaluated as
 * an environment that never sets the key would see it. Restored afterwards either way.
 *
 * Laravel's env repository is immutable: its `clear()` skips any variable it did not load
 * itself, which includes every key phpunit.xml pins. So this hides the key from the places the
 * repository reads (`$_ENV`, `$_SERVER`, `getenv()`) directly, which works wherever the value
 * came from: `.env`, phpunit.xml or the shell.
 */
function withoutEnv(string $key, Closure $probe): mixed
{
    $env = $_ENV[$key] ?? null;
    $server = $_SERVER[$key] ?? null;
    $process = getenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);

    try {
        return $probe();
    } finally {
        if ($env !== null) {
            $_ENV[$key] = $env;
        }
        if ($server !== null) {
            $_SERVER[$key] = $server;
        }
        if ($process !== false) {
            putenv("{$key}={$process}");
        }
    }
}

/**
 * Sign in the way production does — through the OAuth callback, so `Auth::login()` writes
 * identity into the session store. Returns the session id the authenticated session lives
 * under. `actingAs()` cannot stand in for this: it sets the guard's user in memory and
 * leaves the session empty, so there is nothing for a replay to replay.
 */
function logInThroughTheForum(): string
{
    config()->set('services.sevencav', [
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
        'redirect_uri' => 'http://localhost/oauth/callback/7cav',
        'base_url' => 'https://7cav.us',
        'authorize_url' => 'https://7cav.us/oauth2/authorize',
        'scopes' => ['user:read'],
    ]);
    config()->set('services.cav', ['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']);

    MockClient::destroyGlobal();
    MockClient::global([
        ExchangeCodeRequest::class => MockResponse::make(['access_token' => 'tok-abc'], 200),
        FetchProfileRequest::class => MockResponse::make(
            ['me' => ['user_id' => 4, 'username' => 'Gryspeert.J', 'email' => 'j@7cav.us']],
            200,
        ),
        // A real login also enriches from the Cav roster (Story 1.6), so the helper answers
        // that call too — with forum user 4 sitting at milpac id 77 on the combat roster.
        // Callers that care about enrichment specifically should mock it themselves; this
        // keeps the *session* the helper returns representative of a production login.
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => MockResponse::make(['profiles' => [
            '77' => [
                'user' => ['userId' => '4', 'username' => 'Gryspeert.J'],
                'rank' => ['rankShort' => 'SPC', 'rankFull' => 'Specialist', 'rankImageUrl' => '', 'rankId' => '9'],
                'realName' => 'Jens Gryspeert',
                'uniformUrl' => '',
                'roster' => 'ROSTER_TYPE_COMBAT',
                'primary' => ['positionTitle' => 'Rifleman', 'positionId' => '501'],
                'secondaries' => [],
                'joinDate' => '2024-01-08',
                'promotionDate' => '2025-06-01',
                'discordId' => '',
                'lastForumPostDate' => '',
                'mos' => '11B',
                'consoleGamertag' => '',
            ],
        ]]),
    ]);

    // ...and the roster cache holds milpac 77 as serving, as a sync would have left it — the sign-in
    // gate admits only a user linked to a serving cached trooper (or an Admin).
    if (! Trooper::query()->where('cav_id', 77)->exists()) {
        Trooper::factory()->create(['cav_id' => 77]);
    }

    test()->withSession(['forum_oauth_state' => 'valid-state'])
        ->get('/oauth/callback/7cav?code=good-code&state=valid-state')
        ->assertRedirect(route('home'));

    return session()->getId();
}
