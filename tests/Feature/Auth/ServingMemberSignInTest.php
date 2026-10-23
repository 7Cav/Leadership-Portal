<?php

use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The sign-in gate: any 7cav.us forum account completes the OAuth handshake, so a session starts
 * only for an Admin or a serving trooper — as Cav reports them live during the login, or, when Cav
 * cannot answer, as the local roster cache holds their stored link. Cav is faked
 * at the AD-3 seam as in CavEnrichmentTest; the helpers are prefixed so the two files can share a
 * Pest process.
 */
const GATE_NOT_SERVING = "Your forum account isn't linked to a serving 7th Cavalry milpac.";
const GATE_UNVERIFIABLE = "We couldn't reach the 7th Cavalry roster to confirm your membership. Try again in a few minutes.";

/** A `LiteProfile` entry for forum user `$forumUserId`, on `$roster`. */
function gateRosterEntry(int $forumUserId, string $roster = 'ROSTER_TYPE_COMBAT'): array
{
    return [
        'user' => ['userId' => (string) $forumUserId, 'username' => 'Hale.S'],
        'rank' => ['rankShort' => 'SGT', 'rankFull' => 'Sergeant', 'rankImageUrl' => '', 'rankId' => '9'],
        'realName' => '',
        'uniformUrl' => '',
        'roster' => $roster,
        'primary' => ['positionTitle' => 'Squad Leader', 'positionId' => '412'],
        'secondaries' => [],
        'joinDate' => '2021-03-04',
        'promotionDate' => '2025-11-02',
        'discordId' => '',
        'lastForumPostDate' => '',
        'mos' => '11B',
        'consoleGamertag' => '',
    ];
}

/** Every serving roster answering `$response` — what an outage, or an empty roster, looks like. */
function gateRosters(MockResponse $response): array
{
    return [
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => $response,
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_RESERVE/lite' => $response,
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_ELOA/lite' => $response,
    ];
}

/** Forum user 4 completes the handshake; Cav answers with `$cav`. */
function gateSignIn(array $cav): TestResponse
{
    MockClient::destroyGlobal();
    MockClient::global([
        '7cav.us/api/oauth2/token' => MockResponse::make(['access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        '7cav.us/api/me' => MockResponse::make(['me' => ['user_id' => 4, 'username' => 'Hale.S', 'email' => 'hale@7cav.us']]),
        ...$cav,
    ]);

    return test()
        ->withSession(['forum_oauth_state' => 'state-xyz'])
        ->get('/auth/callback?code=auth-code&state=state-xyz');
}

beforeEach(function () {
    config()->set('services.sevencav', [
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
        'redirect_uri' => 'http://localhost/auth/callback',
        'base_url' => 'https://7cav.us',
        'authorize_url' => 'https://7cav.us/oauth2/authorize',
        'scopes' => ['user:read'],
    ]);
    config()->set('services.cav', ['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']);

    // Cav's roster failures are reported; these tests are about who gets in, not the reporting.
    Exceptions::fake();
});

afterEach(fn () => MockClient::destroyGlobal());

it('refuses a forum account that holds no milpac, and says why on the login screen', function () {
    gateSignIn(gateRosters(MockResponse::make(['profiles' => []])))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', GATE_NOT_SERVING);

    $this->assertGuest();
    // The identity row may exist; the session must not.
    expect(User::firstWhere('forum_user_id', 4))->not->toBeNull()
        ->and(session('cav_profile'))->toBeNull();

    $this->get('/login')->assertInertia(fn ($page) => $page->component('Auth/Login')->where('error', GATE_NOT_SERVING));
});

it('signs in a trooper linked to a serving milpac, on any serving roster', function (TrooperStatus $status, string $roster) {
    Trooper::factory()->create(['cav_id' => 77, 'status' => $status]);

    gateSignIn([
        ...gateRosters(MockResponse::make(['profiles' => []])),
        "api.7cav.us/api/v1/roster/{$roster}/lite" => MockResponse::make(['profiles' => ['77' => gateRosterEntry(4, $roster)]]),
    ])->assertRedirect(route('home'));

    $this->assertAuthenticated();
    expect(auth()->user()->cav_id)->toBe(77);
})->with([
    'combat' => [TrooperStatus::Combat, 'ROSTER_TYPE_COMBAT'],
    'reserve' => [TrooperStatus::Reserve, 'ROSTER_TYPE_RESERVE'],
    'ELOA' => [TrooperStatus::Eloa, 'ROSTER_TYPE_ELOA'],
]);

it('signs in on the cache alone while Cav is down, on any serving standing', function (TrooperStatus $status) {
    User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);
    Trooper::factory()->create(['cav_id' => 77, 'status' => $status]);

    gateSignIn(['api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make([], 503)])
        ->assertRedirect(route('home'));

    $this->assertAuthenticated();
})->with([
    'combat' => [TrooperStatus::Combat],
    'reserve' => [TrooperStatus::Reserve],
    'ELOA' => [TrooperStatus::Eloa],
]);

it('refuses a trooper whose milpac the roster cache shows departed, Cav up or down', function (array $cav) {
    User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);
    Trooper::factory()->create(['cav_id' => 77, 'status' => TrooperStatus::Inactive]);

    // A departed trooper is a fact the cache holds on its own, so an outage does not soften it into
    // "try again later".
    gateSignIn($cav)
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', GATE_NOT_SERVING);

    $this->assertGuest();
})->with([
    'Cav reports them on Past Members' => [[
        'api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make(gateRosterEntry(4, 'ROSTER_TYPE_PAST_MEMBERS')),
    ]],
    'Cav is down' => [[
        'api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make([], 503),
    ]],
]);

it('refuses a trooper Cav lists live on a non-serving roster, even while the cache still shows them serving', function (string $roster) {
    // The cache trails Cav by up to one sync; a live answer is the fresher fact.
    User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);
    Trooper::factory()->create(['cav_id' => 77, 'status' => TrooperStatus::Combat]);

    gateSignIn(['api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make(gateRosterEntry(4, $roster))])
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', GATE_NOT_SERVING);

    $this->assertGuest();
})->with([
    'Past Members' => ['ROSTER_TYPE_PAST_MEMBERS'],
    'Wall of Honor' => ['ROSTER_TYPE_WALL_OF_HONOR'],
    'an unrecognized roster' => ['ROSTER_TYPE_UNSPECIFIED'],
]);

it('signs in a trooper Cav lists live on a serving roster whom the cache still shows departed', function () {
    // Back from a discharge since the last sync: Cav's live answer admits them.
    User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);
    Trooper::factory()->create(['cav_id' => 77, 'status' => TrooperStatus::Inactive]);

    gateSignIn(['api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make(gateRosterEntry(4, 'ROSTER_TYPE_RESERVE'))])
        ->assertRedirect(route('home'));

    $this->assertAuthenticated();
});

it('keeps that session past the next request, until a clean roster sync started after the sign-in has run', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', 'UTC'));
    User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);
    Trooper::factory()->create(['cav_id' => 77, 'status' => TrooperStatus::Inactive]);
    // The sync that cached the discharge ran before this sign-in.
    SyncRun::factory()->create(['started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);

    gateSignIn(['api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make(gateRosterEntry(4, 'ROSTER_TYPE_RESERVE'))])
        ->assertRedirect(route('home'));

    // The cache still says departed; the session check must not bounce what Cav admitted moments ago.
    $this->get('/home')->assertOk();

    // A sync that failed wrote nothing, so the cache has not caught up.
    $this->travel(10)->minutes();
    SyncRun::factory()->create(['status' => SyncRunStatus::Failure, 'started_at' => now(), 'finished_at' => now()]);
    $this->get('/home')->assertOk();

    // A clean sync that started after the sign-in and still shows them departed has the last word.
    SyncRun::factory()->create(['started_at' => now(), 'finished_at' => now()]);
    $this->get('/home')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', GATE_NOT_SERVING);
    $this->assertGuest();
});

it('does not let an Admin sign-in outlast the flag when Cav listed them as a past member', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', 'UTC'));
    $admin = User::factory()->admin()->create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);
    Trooper::factory()->create(['cav_id' => 77, 'status' => TrooperStatus::Inactive]);
    SyncRun::factory()->create(['started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);

    // The Admin flag admits them; Cav's answer said they had left, so it vouches for nothing.
    gateSignIn(['api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make(gateRosterEntry(4, 'ROSTER_TYPE_PAST_MEMBERS'))])
        ->assertRedirect(route('home'));

    $admin->forceFill(['is_admin' => false])->save();
    // The next request loads the user afresh, as a real one would.
    auth()->forgetGuards();

    $this->get('/home')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', GATE_NOT_SERVING);
    $this->assertGuest();
});

it('signs in an Admin who holds no milpac — Admins are never locked out by roster data', function () {
    User::factory()->admin()->create(['forum_user_id' => 4]);

    gateSignIn(gateRosters(MockResponse::make(['profiles' => []])))->assertRedirect(route('home'));

    $this->assertAuthenticated();
});

it('signs in a trooper whose stored link the cache confirms as serving while Cav is down (Story 1.7)', function () {
    User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);
    Trooper::factory()->create(['cav_id' => 77]);

    gateSignIn([
        'api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make([], 503),
        ...gateRosters(MockResponse::make([], 503)),
    ])->assertRedirect(route('home'));

    $this->assertAuthenticated();
    // Still degraded-safe: unenriched, with the reason Story 1.7 renders.
    $this->get('/home')->assertInertia(fn ($page) => $page
        ->where('auth.profile', null)
        ->where('auth.profileUnavailable', 'http_error'));
});

it('refuses someone with no stored link while Cav is down, telling them to try again rather than that they are not a member', function () {
    gateSignIn(gateRosters(MockResponse::make([], 503)))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', GATE_UNVERIFIABLE);

    $this->assertGuest();
});

it('signs in a trooper Cav finds live on a serving roster before the roster cache holds them', function () {
    // A brand-new trooper the next sync has not cached yet: Cav's live answer during this login is
    // enough, with no wait for the sync.
    expect(Trooper::query()->where('cav_id', 77)->exists())->toBeFalse();

    gateSignIn([
        ...gateRosters(MockResponse::make(['profiles' => []])),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => MockResponse::make(['profiles' => ['77' => gateRosterEntry(4)]]),
    ])->assertRedirect(route('home'));

    $this->assertAuthenticated();
    expect(User::firstWhere('forum_user_id', 4)->cav_id)->toBe(77);
});
