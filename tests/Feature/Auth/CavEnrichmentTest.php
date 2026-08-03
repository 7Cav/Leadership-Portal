<?php

use App\Commands\Auth\CavEnrichmentException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Story 1.6 — Cav roster enrichment on the login path.
 *
 * Every Cav call is faked at the AD-3 seam, so no HTTP leaves the test. The mocks are keyed
 * by URL rather than request class on purpose: the roster sweep issues the *same* request
 * class three times with different roster types, and only URL keys can answer them
 * differently — which is what makes "found on RESERVE, not COMBAT" testable at all.
 */
const COMBAT_URL = 'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite';
const RESERVE_URL = 'api.7cav.us/api/v1/roster/ROSTER_TYPE_RESERVE/lite';
const ELOA_URL = 'api.7cav.us/api/v1/roster/ROSTER_TYPE_ELOA/lite';

/** A `LiteProfile` entry as api.7cav.us really sends it (field names and wire types verified live). */
function rosterEntry(int $forumUserId, string $username, array $overrides = []): array
{
    return [...[
        'user' => ['userId' => (string) $forumUserId, 'username' => $username],
        'rank' => [
            'rankShort' => 'WO1',
            'rankFull' => 'Warrant Officer 1',
            'rankImageUrl' => 'https://7cav.us/data/roster_ranks/0/12.jpg',
            'rankId' => '12',
        ],
        'realName' => 'Sam Hale',
        'uniformUrl' => '',
        'roster' => 'ROSTER_TYPE_COMBAT',
        'primary' => ['positionTitle' => 'S1 Personnel NCO', 'positionId' => '412'],
        'secondaries' => [],
        'joinDate' => '2021-03-04',
        'promotionDate' => '2025-11-02',
        'discordId' => '',
        'lastForumPostDate' => '',
        'mos' => '42A',
        'consoleGamertag' => '',
    ], ...$overrides];
}

function emptyRoster(): MockResponse
{
    return MockResponse::make(['profiles' => []]);
}

/**
 * Fake the forum handshake plus whatever Cav answers. `$cav` is merged over the default
 * "trooper 4 sits on the combat roster at milpac id 77" world.
 */
function mockLogin(array $cav = [], int $forumUserId = 4, string $username = 'Hale.S'): void
{
    MockClient::destroyGlobal();
    MockClient::global([
        '7cav.us/api/oauth2/token' => MockResponse::make(
            ['access_token' => 'tok-abc', 'token_type' => 'Bearer', 'expires_in' => 3600],
        ),
        '7cav.us/api/me' => MockResponse::make(
            ['me' => ['user_id' => $forumUserId, 'username' => $username, 'email' => 'hale@7cav.us']],
        ),
        ...[...[
            COMBAT_URL => MockResponse::make(['profiles' => ['77' => rosterEntry($forumUserId, $username)]]),
            RESERVE_URL => emptyRoster(),
            ELOA_URL => emptyRoster(),
        ], ...$cav],
    ]);
}

/** Drive a full login through the OAuth callback, with the `state` nonce pre-seeded. */
function login(): TestResponse
{
    return test()
        ->withSession(['forum_oauth_state' => 'state-xyz'])
        ->get('/oauth/callback/7cav?code=auth-code&state=state-xyz');
}

beforeEach(function () {
    config()->set('services.sevencav', [
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
        'redirect_uri' => 'http://localhost/oauth/callback/7cav',
        'base_url' => 'https://7cav.us',
        'authorize_url' => 'https://7cav.us/oauth2/authorize',
        'scopes' => ['user:read'],
    ]);
    config()->set('services.cav', [
        'base_url' => 'https://api.7cav.us',
        'api_key' => 'cav7_test-key',
    ]);

    CarbonImmutable::setTestNow('2026-08-03 09:14:00');
});

afterEach(function () {
    MockClient::destroyGlobal();
    CarbonImmutable::setTestNow();
});

it('enriches the session with the trooper\'s rank, billet and MOS on login (AC #1)', function () {
    mockLogin();

    login()->assertRedirect(route('home'));

    expect(session('cav_profile'))
        ->rank_short->toBe('WO1')
        ->rank_full->toBe('Warrant Officer 1')
        ->billet->toBe('S1 Personnel NCO')
        ->mos->toBe('42A');
});

it('persists the discovered milpac id as cav_id (AC #6)', function () {
    mockLogin();

    login();

    // 77 is the roster map key, not the forum user id — the two must not be confused.
    expect(User::firstWhere('forum_user_id', 4)->cav_id)->toBe(77);
});

it('matches on the forum user id, never on the username (AC #6)', function () {
    // A same-named trooper sits at a different forum id; ours is elsewhere on the roster.
    mockLogin(cav: [
        COMBAT_URL => MockResponse::make(['profiles' => [
            '11' => rosterEntry(999, 'Hale.S', ['mos' => 'WRONG']),
            '77' => rosterEntry(4, 'Hale.S'),
        ]]),
    ]);

    login();

    expect(User::firstWhere('forum_user_id', 4)->cav_id)->toBe(77)
        ->and(session('cav_profile')['mos'])->toBe('42A');
});

it('sweeps past a roster the trooper is not on (AC #1)', function () {
    mockLogin(cav: [
        COMBAT_URL => emptyRoster(),
        RESERVE_URL => MockResponse::make(['profiles' => ['31' => rosterEntry(4, 'Hale.S', [
            'roster' => 'ROSTER_TYPE_RESERVE',
        ])]]),
    ]);

    login();

    expect(User::firstWhere('forum_user_id', 4)->cav_id)->toBe(31)
        ->and(session('cav_profile')['roster'])->toBe('ROSTER_TYPE_RESERVE');
});

it('goes straight to the profile endpoint once cav_id is known, and does not sweep again (AC #6)', function () {
    User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);

    mockLogin(cav: [
        // Any sweep would now be a bug: the rosters answer 500 so one would fail loudly.
        COMBAT_URL => MockResponse::make(['code' => 13, 'message' => 'must not be called'], 500),
        RESERVE_URL => MockResponse::make([], 500),
        ELOA_URL => MockResponse::make([], 500),
        'api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make(rosterEntry(4, 'Hale.S', [
            'mos' => '11B',
        ])),
    ]);

    login()->assertRedirect(route('home'));

    expect(session('cav_profile')['mos'])->toBe('11B');
});

it('re-resolves and relinks when the stored cav_id points at another trooper (AC #6)', function () {
    // A milpac id that has been reassigned. Rendering the record it now returns would show a
    // leader someone else's rank, so the mismatch must be caught, not trusted.
    User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);

    mockLogin(cav: [
        'api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make(rosterEntry(999, 'Someone.Else', [
            'mos' => 'WRONG',
        ])),
        COMBAT_URL => MockResponse::make(['profiles' => ['81' => rosterEntry(4, 'Hale.S')]]),
    ]);

    login()->assertRedirect(route('home'));

    expect(User::firstWhere('forum_user_id', 4)->cav_id)->toBe(81)
        ->and(session('cav_profile')['mos'])->toBe('42A');
});

it('stamps the source and fetch time onto the enriched values (AC #3)', function () {
    mockLogin();

    login();

    expect(session('cav_profile'))
        ->source->toBe('Cav roster (api.7cav.us)')
        ->fetched_at->toBe('2026-08-03T09:14:00+00:00');
});

it('shares the enriched profile with the front end so it can be rendered (AC #3)', function () {
    mockLogin();
    login();

    $this->get('/home')->assertInertia(fn ($page) => $page
        ->where('auth.profile.rank_short', 'WO1')
        ->where('auth.profile.billet', 'S1 Personnel NCO')
        ->where('auth.profile.mos', '42A')
        ->where('auth.profile.source', 'Cav roster (api.7cav.us)')
        ->where('auth.profile.fetched_at', '2026-08-03T09:14:00+00:00'));
});

it('never writes to Cav — every call the flow makes is a GET (AC #4)', function () {
    mockLogin();

    login();

    $cavRequests = collect(MockClient::global()->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest())
        ->filter(fn ($pending) => str_contains($pending->getUrl(), 'api.7cav.us'));

    expect($cavRequests)->not->toBeEmpty()
        ->and($cavRequests->every(fn ($pending) => $pending->getMethod()->value === 'GET'))->toBeTrue();
});

it('signs the user in unenriched when they hold no milpac, and reports why (AC #1)', function () {
    Exceptions::fake();

    mockLogin(cav: [COMBAT_URL => emptyRoster()]);

    login()->assertRedirect(route('home'));

    $this->assertAuthenticated();
    expect(session('cav_profile'))->toBeNull();
    // Degrading is not the same as failing silently: 1.7 owns the user-facing story, but the
    // cause has to be observable now or an outage looks like "everyone lost their rank".
    Exceptions::assertReported(CavEnrichmentException::class);
});

it('keeps login working when the roster API is unreachable (AC #1; degraded UX is 1.7)', function () {
    Exceptions::fake();

    mockLogin(cav: [COMBAT_URL => MockResponse::make(['code' => 13, 'message' => 'internal'], 500)]);

    login()->assertRedirect(route('home'));

    $this->assertAuthenticated();
    expect(session('cav_profile'))->toBeNull();
    Exceptions::assertReported(CavEnrichmentException::class);
});

it('does not send a Cav request at all when no API key is configured', function () {
    Exceptions::fake();
    config()->set('services.cav.api_key', null);

    mockLogin();

    login()->assertRedirect(route('home'));

    // A missing credential is a deployment fault. It must not read as "not on the roster",
    // and it must not go out as an unauthenticated request either.
    $sent = collect(MockClient::global()->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest()->getUrl())
        ->filter(fn ($url) => str_contains($url, 'api.7cav.us'));

    expect($sent)->toBeEmpty();
    Exceptions::assertReported(CavEnrichmentException::class);
});

it('carries the snapshot into the next request, not just the callback response', function () {
    // The enriched values are only useful if they outlive the redirect: every page that
    // renders a rank reads them from the session on a *later* request, not the callback.
    // (This does not pin where in the callback enrichment sits — `regenerate()` migrates the
    // session id but carries its attributes across, so either order survives.)
    mockLogin();

    login();

    $this->get('/home')->assertOk()->assertInertia(fn ($page) => $page->has('auth.profile'));
});
