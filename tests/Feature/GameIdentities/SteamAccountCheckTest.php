<?php

use App\Integration\Steam\SteamApi;
use App\Integration\Steam\SteamUnavailable;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

// #174: a Steam ID is asked about on Steam when it is linked, and one Steam does not know is refused. Every call is
// faked with Saloon's MockClient; nothing here touches the real API.

const STEAM_URL = 'api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/*';
const STEAM_KNOWN = '76561198000000174';

beforeEach(function () {
    config(['services.steam.web_api_key' => 'test-steam-key']);
    app()->forgetInstance(SteamApi::class);

    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '940/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'path' => '940/941/', 'callsign' => '1/A']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->mine = Trooper::factory()->create(['username' => 'Ortega.M']);
    $this->mine->forceFill(['primary_section_id' => $this->section->id])->save();
    game('hll')->update(['id_kinds' => ['Steam ID', 'Epic ID', 'Xbox ID']]);
});

/** Steam's answer: the accounts it knows among those asked about. */
function steamAnswer(array $known): MockResponse
{
    return MockResponse::make(['response' => ['players' => array_map(fn (string $id) => ['steamid' => $id, 'personaname' => 'x'], $known)]]);
}

function steamSave(object $test, string $id, string $kind = 'Steam ID', string $path = ''): TestResponse
{
    $url = "/game-identities/{$test->mine->id}/hll{$path}";
    $body = ['platform_id' => $id, 'id_kind' => $kind];

    return $path === '' ? $test->actingAs($test->leader)->put($url, $body) : $test->actingAs($test->leader)->post($url, $body);
}

it('saves a Steam ID Steam knows, asking once with the key and the ID', function () {
    $mock = MockClient::global([STEAM_URL => steamAnswer([STEAM_KNOWN])]);

    steamSave($this, STEAM_KNOWN)->assertSessionHasNoErrors();

    expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe(STEAM_KNOWN);
    $query = $mock->getLastPendingRequest()->query()->all();
    expect($query)->toBe(['key' => 'test-steam-key', 'steamids' => STEAM_KNOWN]);
});

it('refuses a Steam ID Steam has no account for, writing and logging nothing', function (string $path) {
    MockClient::global([STEAM_URL => steamAnswer([])]);

    steamSave($this, STEAM_KNOWN, path: $path)->assertSessionHasErrors(['platform_id' => 'Steam has no account with this ID.']);

    expect(TrooperGameIdentity::query()->count())->toBe(0)->and(AuditLogEntry::query()->count())->toBe(0);
})->with(['one game' => [''], 'every game' => ['/account']]);

it('saves unchecked when Steam fails or is unreachable', function (MockResponse|Closure $answer) {
    MockClient::global([STEAM_URL => $answer]);

    steamSave($this, STEAM_KNOWN)->assertSessionHasNoErrors();

    expect(TrooperGameIdentity::query()->count())->toBe(1);
})->with([
    'server error' => fn () => MockResponse::make('boom', 500),
    'bad key' => fn () => MockResponse::make('', 403),
    'not json' => fn () => MockResponse::make('<html>', 200),
    'no player list' => fn () => MockResponse::make(['response' => []], 200),
    'transport error' => fn () => fn (PendingRequest $request) => throw new RuntimeException('cURL error 28 for '.$request->getUrl()),
]);

it('saves unchecked, asking nothing, when no key is configured', function () {
    config(['services.steam.web_api_key' => '']);
    app()->forgetInstance(SteamApi::class);
    $mock = MockClient::global([STEAM_URL => steamAnswer([])]);

    steamSave($this, STEAM_KNOWN)->assertSessionHasNoErrors();

    expect(TrooperGameIdentity::query()->count())->toBe(1);
    $mock->assertNothingSent();
});

it('asks nothing about an ID that is not a Steam ID, or a Steam ID saved again unchanged', function () {
    $mock = MockClient::global([STEAM_URL => steamAnswer([STEAM_KNOWN])]);

    steamSave($this, '0002105c01b748ada8d8cf5b32e70093', 'Epic ID')->assertSessionHasNoErrors();
    $mock->assertNothingSent();

    steamSave($this, STEAM_KNOWN)->assertSessionHasNoErrors();
    steamSave($this, STEAM_KNOWN)->assertSessionHasNoErrors();
    $mock->assertSentCount(1);
});

it('never puts the key in a failure', function () {
    MockClient::destroyGlobal();

    // Port 1 refuses immediately, so no network is needed.
    try {
        (new SteamApi(['base_url' => 'http://127.0.0.1:1', 'web_api_key' => 'secret-steam-key']))->accountExists(STEAM_KNOWN);
        $this->fail('Expected a SteamUnavailable.');
    } catch (SteamUnavailable $e) {
        expect($e->getMessage())->not->toContain('secret-steam-key')->and($e->getPrevious())->toBeNull();
    }
});
