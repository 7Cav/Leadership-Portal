<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The sign-in gate: any 7cav.us forum account completes the OAuth handshake, so a session starts
 * only for an Admin or a user linked to a serving trooper in the local roster cache. Cav is faked
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

it('signs in a trooper linked to a serving milpac', function (TrooperStatus $status) {
    Trooper::factory()->create(['cav_id' => 77, 'status' => $status]);

    gateSignIn([
        ...gateRosters(MockResponse::make(['profiles' => []])),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => MockResponse::make(['profiles' => ['77' => gateRosterEntry(4)]]),
    ])->assertRedirect(route('home'));

    $this->assertAuthenticated();
    expect(auth()->user()->cav_id)->toBe(77);
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

it('refuses a trooper Cav finds but the roster cache does not hold yet, until the next roster sync', function () {
    // Pinned so the trade-off is visible: the gate reads the local cache only, so a brand-new
    // trooper waits for the sync that caches them. The link is kept, so that sync is all it takes.
    gateSignIn([
        ...gateRosters(MockResponse::make(['profiles' => []])),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => MockResponse::make(['profiles' => ['77' => gateRosterEntry(4)]]),
    ])->assertRedirect(route('login'));

    $this->assertGuest();
    expect(User::firstWhere('forum_user_id', 4)->cav_id)->toBe(77);

    Trooper::factory()->create(['cav_id' => 77]);

    gateSignIn([
        'api.7cav.us/api/v1/milpacs/profile/id/77' => MockResponse::make(gateRosterEntry(4)),
    ])->assertRedirect(route('home'));

    $this->assertAuthenticated();
});
