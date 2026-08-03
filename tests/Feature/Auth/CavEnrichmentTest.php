<?php

use App\Commands\Auth\CavEnrichmentException;
use App\Models\User;
use App\Repositories\IdentityLinkException;
use App\Repositories\UserRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
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
        ->and(session('cav_profile')['roster'])->toBe('Reserve');
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

it('signs the user in unenriched when they hold no milpac, recorded at info not error (AC #1)', function () {
    Exceptions::fake();
    Log::spy();

    mockLogin(cav: [COMBAT_URL => emptyRoster()]);

    login()->assertRedirect(route('home'));

    $this->assertAuthenticated();
    expect(session('cav_profile'))->toBeNull()
        // Story 1.7 needs to tell "holds no milpac" apart from "roster is down".
        ->and(session('cav_profile_unavailable'))->toBe('not_on_roster');

    // A forum member with no milpac is a normal population, so this must NOT be an error with a
    // stack trace on every one of their logins — that noise is what makes a real outage
    // invisible. Observable, but at the level the event deserves.
    Exceptions::assertNothingReported();
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message) => str_contains($message, 'holds no milpac'))
        ->once();
});

it('keeps login working when the roster API returns an error status (AC #1; degraded UX is 1.7)', function () {
    Exceptions::fake();

    mockLogin(cav: [
        COMBAT_URL => MockResponse::make(['code' => 13, 'message' => 'internal'], 500),
        RESERVE_URL => MockResponse::make([], 500),
        ELOA_URL => MockResponse::make([], 500),
    ]);

    login()->assertRedirect(route('home'));

    $this->assertAuthenticated();
    expect(session('cav_profile'))->toBeNull()
        // NOT 'not_on_roster': no roster actually answered, so claiming the trooper holds no
        // milpac would be an unsupported conclusion.
        ->and(session('cav_profile_unavailable'))->toBe('http_error');
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

    expect($sent)->toBeEmpty()
        ->and(session('cav_profile_unavailable'))->toBe('missing_credential');
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

it('re-resolves a dead milpac id instead of leaving the trooper stuck on it (AC #6)', function () {
    // A 404 on the stored link is the same situation as a reassigned one: the link is wrong.
    // Without a re-sweep, `cav_id` stays 77 and this trooper is unenriched on EVERY future
    // login — permanently, with no way back short of editing the database by hand.
    User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);

    mockLogin(cav: [
        'api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make(['code' => 5, 'message' => 'not found'], 404),
        COMBAT_URL => MockResponse::make(['profiles' => ['81' => rosterEntry(4, 'Hale.S')]]),
    ]);

    login()->assertRedirect(route('home'));

    expect(User::firstWhere('forum_user_id', 4)->cav_id)->toBe(81)
        ->and(session('cav_profile')['mos'])->toBe('42A');
});

it('does not sweep on the back of a transport failure, which says nothing about the link', function () {
    Exceptions::fake();
    // Distinct from the 404 above: an unreachable API is no evidence the stored id is wrong, so
    // re-sweeping would be guesswork — and would cost three more doomed calls on a login.
    User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);
    config()->set('services.cav.api_key', '');

    mockLogin();

    login()->assertRedirect(route('home'));

    expect(User::firstWhere('forum_user_id', 4)->cav_id)->toBe(77)
        ->and(session('cav_profile_unavailable'))->toBe('missing_credential');
});

it('clears the previous trooper\'s roster context when enrichment fails on a shared session', function () {
    // The leak this guards: `session()->regenerate()` migrates the session id but carries its
    // attributes across, so without an explicit forget, trooper A's rank/billet/MOS survives
    // into trooper B's login — stamped with a source and fetch time that make it look
    // authoritative. B is then rendered wearing A's rank.
    mockLogin();
    login();
    expect(session('cav_profile')['mos'])->toBe('42A');

    Exceptions::fake();
    mockLogin(cav: [
        COMBAT_URL => MockResponse::make([], 500),
        RESERVE_URL => MockResponse::make([], 500),
        ELOA_URL => MockResponse::make([], 500),
    ], forumUserId: 999, username: 'Other.P');

    login()->assertRedirect(route('home'));

    expect(session('cav_profile'))->toBeNull();
    $this->get('/home')->assertInertia(fn ($page) => $page->where('auth.profile', null));
});

it('finds the trooper on a later roster even when an earlier one is down (AC #1)', function () {
    // A hit is definitive regardless of what COMBAT did, so a COMBAT outage must not deny a
    // RESERVE trooper their context.
    mockLogin(cav: [
        COMBAT_URL => MockResponse::make([], 503),
        RESERVE_URL => MockResponse::make(['profiles' => ['31' => rosterEntry(4, 'Hale.S')]]),
    ]);

    login()->assertRedirect(route('home'));

    expect(User::firstWhere('forum_user_id', 4)->cav_id)->toBe(31)
        ->and(session('cav_profile')['mos'])->toBe('42A');
});

it('reassigns a milpac id away from its previous holder, atomically and audibly', function () {
    Log::spy();
    // `cav_id` is unique, so without clearing the previous holder first this write throws a
    // constraint violation AFTER Auth::login() — an uncatchable 500 mid-login.
    $previous = User::create(['forum_user_id' => 555, 'cav_id' => 77, 'username' => 'Previous.H']);

    mockLogin();

    login()->assertRedirect(route('home'));

    expect(User::firstWhere('forum_user_id', 4)->cav_id)->toBe(77)
        ->and($previous->fresh()->cav_id)->toBeNull();

    // Destroying a portal-owned identity link on the strength of an external payload must leave
    // a trace, or the null is unexplainable when Epic 2's sync later reads it.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'Reassigning a Cav milpac id'))
        ->once();
});

it('logs the stale link before re-resolving, so a systematic re-keying is visible', function () {
    Log::spy();
    User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);

    mockLogin(cav: [
        'api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make(rosterEntry(999, 'Someone.Else')),
        COMBAT_URL => MockResponse::make(['profiles' => ['81' => rosterEntry(4, 'Hale.S')]]),
    ]);

    login();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'Stored Cav milpac link is stale'))
        ->once();
});

it('degrades rather than 500s when the roster answers 200 with a non-object body', function () {
    Exceptions::fake();
    // Saloon substitutes `[]` only for an EMPTY body; `null`, a number or a bare string decode
    // to a non-array and would hit the seam's `array` return type as a TypeError — which no
    // handler catches, so every login would 500 for as long as the roster answered that way.
    mockLogin(cav: [
        COMBAT_URL => MockResponse::make('null'),
        RESERVE_URL => MockResponse::make('123'),
        ELOA_URL => MockResponse::make('"just a string"'),
    ]);

    login()->assertRedirect(route('home'));

    $this->assertAuthenticated();
    expect(session('cav_profile'))->toBeNull()
        ->and(session('cav_profile_unavailable'))->toBe('malformed');
});

it('does not claim the trooper has no milpac when their own roster entry is unusable', function () {
    Exceptions::fake();
    // The roster DOES carry forum user 4, but their record is broken. Reporting "no milpac"
    // would assert the opposite of the truth and send whoever debugs it at the wrong system.
    mockLogin(cav: [
        // Key '77.9' is not a valid milpac id, but the row IS forum user 4's.
        COMBAT_URL => MockResponse::make(['profiles' => ['77.9' => rosterEntry(4, 'Hale.S')]]),
    ]);

    login()->assertRedirect(route('home'));

    expect(session('cav_profile'))->toBeNull()
        // Deliberately NOT 'not_on_roster' — the roster plainly carries them.
        ->and(session('cav_profile_unavailable'))->toBe('record_unusable');
});

it('reads the Cav credential from env only, never a default in the repo (AC #5)', function () {
    // AD-23/NFR2: the key is environment-only. A committed fallback would be a secret in the
    // repo, so the config must resolve to null when the env key is absent — while the *base URL*
    // (not a secret) may safely default.
    $cav = withoutEnv('CAV_API_KEY', fn () => require config_path('services.php'))['cav'];

    expect($cav['api_key'])->toBeNull()
        ->and($cav['base_url'])->toBe('https://api.7cav.us');
});

it('degrades rather than 500s when the identity link cannot be written', function () {
    Exceptions::fake();
    // `cav_id` is unique, so two concurrent logins resolving the same milpac id can still
    // collide despite the transaction. That failure arrives after Auth::login(), so if it
    // escaped it would 500 a request whose user is already authenticated.
    $this->mock(UserRepository::class, function ($mock) {
        $mock->shouldReceive('upsertFromForumIdentity')
            ->andReturn(User::create(['forum_user_id' => 4, 'username' => 'Hale.S']));
        $mock->shouldReceive('linkCavId')
            ->andThrow(IdentityLinkException::cavIdNotLinked(1, 77, new RuntimeException('duplicate key')));
    });

    mockLogin();

    login()->assertRedirect(route('home'));

    $this->assertAuthenticated();
    expect(session('cav_profile'))->toBeNull()
        ->and(session('cav_profile_unavailable'))->toBe('link_failed');
    Exceptions::assertReported(CavEnrichmentException::class);
});
