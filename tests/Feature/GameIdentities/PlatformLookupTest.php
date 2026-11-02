<?php

use App\Commands\GameIdentities\ImportGameIdentities;
use App\Commands\GameIdentities\PlatformLookup;
use App\Integration\Crcon\CrconApi;
use App\Integration\Crcon\CrconUnavailable;
use App\Integration\Steam\SteamApi;
use App\Models\AuditLogEntry;
use App\Models\IdKindFormat;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// "Fill in from game server": a player's platform and current name from CRCON's get_player_profile, on the add form,
// on a saved identity and in the bulk import, and the gamertag that shows beside the ID. Every CRCON call is faked
// with Saloon's MockClient; nothing here reaches a server.

const LOOKUP_PROFILE_URL = 'crcon.test/api/get_player_profile*';
const XBOX_ID = 'e677aaaabbbbccccddddeeeeffff0001';
const PSN_ID = 'e677aaaabbbbccccddddeeeeffff0002';

function lookupProfile(string $platform, string $name = 'Cain J'): MockResponse
{
    return MockResponse::make(['result' => [
        'player_id' => XBOX_ID,
        'steam_id' => null,
        'names' => [['name' => $name, 'player_id' => XBOX_ID, 'created' => '2026-08-01T00:00:00+00:00', 'last_seen' => '2026-08-10T00:23:38+00:00']],
        'soldier' => ['eos_id' => '0002c28e'.str_repeat('0', 24), 'name' => $name, 'level' => 182, 'platform' => $platform, 'clan_tag' => '7CAV'],
    ], 'command' => 'get_player_profile', 'failed' => false, 'error' => null, 'version' => 'v12.3.0']);
}

function lookupUnknown(): MockResponse
{
    return MockResponse::make(['result' => null, 'command' => 'get_player_profile', 'failed' => true, 'error' => 'Player not found', 'version' => 'v12.3.0']);
}

function lookupCalls(): int
{
    return count(MockClient::global()->getRecordedResponses());
}

beforeEach(function () {
    config(['services.crcon' => ['base_url' => 'https://crcon.test', 'api_key' => 'test-key'], 'services.steam.web_api_key' => '']);
    app()->forgetInstance(CrconApi::class);
    app()->forgetInstance(SteamApi::class);
    Cache::flush();
    MockClient::destroyGlobal();

    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '960/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'path' => '960/961/']);
    $other = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'path' => '960/962/']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->novak = Trooper::factory()->create(['username' => 'Novak.E', 'rank_short' => 'SGT']);
    $this->novak->forceFill(['primary_section_id' => $this->section->id])->save();
    $this->outside = Trooper::factory()->create(['username' => 'Nakamura.T']);
    $this->outside->forceFill(['primary_section_id' => $other->id])->save();
});

afterEach(fn () => MockClient::destroyGlobal());

describe('the CRCON read', function () {
    it('reads the platform and current name from the profile', function () {
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile('XBL')]);

        $profile = app(CrconApi::class)->playerProfile(XBOX_ID);

        expect($profile->platform)->toBe('xbl')->and($profile->name)->toBe('Cain J');
    });

    it('reads an unknown player as not seen, not as an outage', function () {
        MockClient::global([LOOKUP_PROFILE_URL => lookupUnknown()]);

        expect(app(CrconApi::class)->playerProfile(XBOX_ID))->toBeNull();
    });

    it('fails typed when the server answers with an error', function () {
        MockClient::global([LOOKUP_PROFILE_URL => MockResponse::make('', 502)]);

        app(CrconApi::class)->playerProfile(XBOX_ID);
    })->throws(CrconUnavailable::class);
});

describe('the lookup', function () {
    it('maps each platform to its kind, and one the game does not ask for to none', function (string $platform, ?string $kind) {
        game('hll')->update(['id_kinds' => ['Steam ID', 'Epic ID', 'Xbox ID', 'PlayStation ID']]);
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile($platform)]);

        $result = app(PlatformLookup::class)->lookup(game('hll'), XBOX_ID);

        expect($result->status)->toBe('found')->and($result->kind)->toBe($kind)->and($result->name)->toBe('Cain J');
    })->with([
        'steam' => ['steam', 'Steam ID'],
        'epic' => ['epic', 'Epic ID'],
        'xbox' => ['xbl', 'Xbox ID'],
        'playstation' => ['psn', 'PlayStation ID'],
        'vietnam steam' => ['EPlatformFamily::Steam', 'Steam ID'],
        'vietnam epic' => ['EPlatformFamily::EGS', 'Epic ID'],
        'vietnam xbox' => ['EPlatformFamily::XboxSeries', 'Xbox ID'],
        'vietnam playstation' => ['EPlatformFamily::PS5', 'PlayStation ID'],
        'vietnam ps4' => ['EPlatformFamily::PS4', 'PlayStation ID'],
        'unknown' => ['switch', null],
    ]);

    it('maps a platform to no kind when the game does not ask for that kind', function () {
        game('hll')->update(['id_kinds' => ['Steam ID', 'Epic ID']]);
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile('xbl')]);

        expect(app(PlatformLookup::class)->lookup(game('hll'), XBOX_ID)->kind)->toBeNull();
    });

    it('says not checked when the server is down, and keeps no answer', function () {
        MockClient::global([LOOKUP_PROFILE_URL => MockResponse::make('', 500)]);
        $failed = false;

        expect(app(PlatformLookup::class)->lookup(game('hll'), XBOX_ID, $failed)->status)->toBe('not_checked')
            ->and($failed)->toBeTrue()
            ->and(app(PlatformLookup::class)->cached(game('hll'), XBOX_ID))->toBeNull();
    });

    it('asks nothing for a game not read through CRCON', function () {
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile('xbl')]);

        expect(app(PlatformLookup::class)->lookup(game('arma'), XBOX_ID)->status)->toBe('not_checked')->and(lookupCalls())->toBe(0);
    });
});

describe('the add form', function () {
    it('answers the kind and gamertag for a trooper the leader may change', function () {
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile('xbl')]);

        $this->actingAs($this->leader)->postJson("/game-identities/{$this->novak->id}/hll/lookup", ['platform_id' => strtoupper(XBOX_ID)])
            ->assertOk()->assertExactJson(['status' => 'found', 'kind' => 'Xbox ID', 'platform' => 'xbl', 'name' => 'Cain J']);
    });

    it('answers not seen for a player no server knows', function () {
        MockClient::global([LOOKUP_PROFILE_URL => lookupUnknown()]);

        $this->actingAs($this->leader)->postJson("/game-identities/{$this->novak->id}/hll/lookup", ['platform_id' => XBOX_ID])
            ->assertOk()->assertJsonPath('status', 'not_seen');
    });

    it('refuses a trooper outside the leader\'s scope', function () {
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile('xbl')]);

        $this->actingAs($this->leader)->postJson("/game-identities/{$this->outside->id}/hll/lookup", ['platform_id' => XBOX_ID])->assertForbidden();
        expect(lookupCalls())->toBe(0);
    });

    it('saves a typed gamertag as typed, and one the lookup gave as from the game server', function () {
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile('xbl')]);
        $this->actingAs($this->leader)->postJson("/game-identities/{$this->novak->id}/hll/lookup", ['platform_id' => XBOX_ID])->assertOk();

        $this->actingAs($this->leader)->put("/game-identities/{$this->novak->id}/hll", ['platform_id' => XBOX_ID, 'id_kind' => 'Xbox ID', 'gamertag' => 'Cain J', 'gamertag_from_server' => true])
            ->assertSessionHasNoErrors();
        $row = TrooperGameIdentity::query()->sole();
        expect($row->gamertag)->toBe('Cain J')->and($row->gamertag_from_server)->toBeTrue();

        $this->actingAs($this->leader)->put("/game-identities/{$this->novak->id}/hll", ['platform_id' => XBOX_ID, 'id_kind' => 'Xbox ID', 'gamertag' => 'Someone Else', 'gamertag_from_server' => true])
            ->assertSessionHasNoErrors();
        $row->refresh();
        expect($row->gamertag)->toBe('Someone Else')->and($row->gamertag_from_server)->toBeFalse()
            ->and(json_encode(AuditLogEntry::query()->latest('id')->first()->details))->toContain('Gamertag');
    });

    it('keeps the gamertag when the kind is saved again without one, and drops it with a new ID', function () {
        $row = TrooperGameIdentity::factory()->create(['trooper_id' => $this->novak->id, 'game_id' => game('hll')->id, 'platform_id' => XBOX_ID, 'id_kind' => 'Xbox ID', 'gamertag' => 'Cain J']);

        $this->actingAs($this->leader)->put("/game-identities/{$this->novak->id}/hll", ['platform_id' => XBOX_ID, 'id_kind' => 'Epic ID'])->assertSessionHasNoErrors();
        expect($row->refresh()->gamertag)->toBe('Cain J');

        $this->actingAs($this->leader)->put("/game-identities/{$this->novak->id}/hll", ['platform_id' => PSN_ID, 'id_kind' => 'Epic ID'])->assertSessionHasNoErrors();
        expect($row->refresh()->gamertag)->toBeNull();
    });

    it('shows the gamertag, where it came from and whether the game can be asked', function () {
        TrooperGameIdentity::factory()->create(['trooper_id' => $this->novak->id, 'game_id' => game('hll')->id, 'platform_id' => XBOX_ID, 'id_kind' => 'Xbox ID', 'gamertag' => 'Cain J', 'gamertag_from_server' => true]);

        $this->actingAs($this->leader)->get('/game-identities')->assertInertia(fn (Assert $page) => $page
            ->where('troopers.0.identities.hll.gamertag', 'Cain J')
            ->where('troopers.0.identities.hll.gamertagFromServer', true)
            ->where('platforms', fn ($platforms) => collect($platforms)->firstWhere('value', 'hll')['lookup'] === true
                && collect($platforms)->firstWhere('value', 'arma')['lookup'] === false));
    });
});

describe('a saved identity', function () {
    it('takes the server\'s kind and gamertag in one audited update', function () {
        $row = TrooperGameIdentity::factory()->create(['trooper_id' => $this->novak->id, 'game_id' => game('hll')->id, 'platform_id' => XBOX_ID, 'id_kind' => 'Epic ID']);
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile('xbl')]);

        $this->actingAs($this->leader)->post("/game-identities/{$this->novak->id}/hll/fill")->assertSessionHas('notice', 'Filled in from the game server.');

        $row->refresh();
        expect($row->id_kind)->toBe('Xbox ID')->and($row->gamertag)->toBe('Cain J')->and($row->gamertag_from_server)->toBeTrue()
            ->and($row->platform_id)->toBe(XBOX_ID)
            ->and(AuditLogEntry::query()->sole()->action)->toBe('game_identity.updated');
    });

    it('writes and logs nothing when the server says nothing new', function () {
        TrooperGameIdentity::factory()->create(['trooper_id' => $this->novak->id, 'game_id' => game('hll')->id, 'platform_id' => XBOX_ID, 'id_kind' => 'Xbox ID', 'gamertag' => 'Cain J', 'gamertag_from_server' => true]);
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile('xbl')]);
        $this->actingAs($this->leader)->post("/game-identities/{$this->novak->id}/hll/fill")->assertSessionHas('notice', 'Already up to date with the game server.');

        expect(AuditLogEntry::query()->count())->toBe(0);
    });

    it('writes nothing when no server has seen the player', function () {
        TrooperGameIdentity::factory()->create(['trooper_id' => $this->novak->id, 'game_id' => game('hll')->id, 'platform_id' => XBOX_ID, 'id_kind' => 'Epic ID']);
        MockClient::global([LOOKUP_PROFILE_URL => lookupUnknown()]);

        $this->actingAs($this->leader)->post("/game-identities/{$this->novak->id}/hll/fill")->assertSessionHas('notice', 'Not seen on our servers. Check the ID, or choose the kind yourself.');

        expect(AuditLogEntry::query()->count())->toBe(0)->and(TrooperGameIdentity::query()->sole()->id_kind)->toBe('Epic ID');
    });

    it('refuses a trooper outside the leader\'s scope', function () {
        TrooperGameIdentity::factory()->create(['trooper_id' => $this->outside->id, 'game_id' => game('hll')->id, 'platform_id' => XBOX_ID, 'id_kind' => 'Epic ID']);

        $this->actingAs($this->leader)->post("/game-identities/{$this->outside->id}/hll/fill")->assertForbidden();
    });
});

describe('the bulk import', function () {
    it('looks up a ? or empty kind and fills the gamertag, then saves what the preview showed without asking again', function () {
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile('xbl')]);
        $text = 'Novak.E, HLL, ?, '.XBOX_ID;

        $line = $this->actingAs($this->leader)->postJson('/game-identities/import/preview', ['text' => $text])->assertOk()->json('lines.0');
        expect($line)->toMatchArray(['status' => 'add', 'idKind' => 'Xbox ID', 'lookup' => 'found', 'gamertag' => 'Cain J', 'gamertagFromServer' => true]);

        $this->actingAs($this->leader)->post('/game-identities/import', ['text' => $text, 'expected' => [1]])->assertSessionHasNoErrors();

        $row = TrooperGameIdentity::query()->sole();
        expect($row->id_kind)->toBe('Xbox ID')->and($row->gamertag)->toBe('Cain J')->and($row->gamertag_from_server)->toBeTrue()
            ->and(lookupCalls())->toBe(1);
    });

    it('keeps a typed gamertag over the server\'s, and an empty kind asks too', function () {
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile('xbl')]);

        $line = $this->actingAs($this->leader)->postJson('/game-identities/import/preview', ['text' => 'Novak.E, HLL, , '.XBOX_ID.', Typed Tag'])->json('lines.0');

        expect($line)->toMatchArray(['status' => 'add', 'idKind' => 'Xbox ID', 'gamertag' => 'Typed Tag', 'gamertagFromServer' => false]);
    });

    it('refuses a player not seen until a kind is given', function () {
        MockClient::global([LOOKUP_PROFILE_URL => lookupUnknown()]);

        $line = $this->actingAs($this->leader)->postJson('/game-identities/import/preview', ['text' => 'Novak.E, HLL, ?, '.XBOX_ID])->json('lines.0');

        expect($line)->toMatchArray(['status' => 'refused', 'lookup' => 'not_seen', 'reason' => 'Not seen on our servers. Choose a kind.']);
    });

    it('refuses a ? on a game not read through CRCON', function () {
        $line = $this->actingAs($this->leader)->postJson('/game-identities/import/preview', ['text' => 'Novak.E, arma, ?, '.XBOX_ID])->json('lines.0');

        expect($line['status'])->toBe('refused')->and($line['reason'])->toContain('cannot be asked');
    });

    it('stops asking after the first failure and refuses the rest as not checked', function () {
        Trooper::factory()->count(2)->sequence(['username' => 'Dube.R'], ['username' => 'Ortega.M'])->create()
            ->each(fn (Trooper $t) => $t->forceFill(['primary_section_id' => $this->section->id])->save());
        MockClient::global([LOOKUP_PROFILE_URL => MockResponse::make('', 503)]);
        $text = 'Novak.E, HLL, ?, '.XBOX_ID."\nDube.R, HLL, ?, ".PSN_ID."\nOrtega.M, HLL, ?, ".str_repeat('c', 32);

        $lines = $this->actingAs($this->leader)->postJson('/game-identities/import/preview', ['text' => $text])->json('lines');

        expect(collect($lines)->pluck('lookup')->all())->toBe(['not_checked', 'not_checked', 'not_checked'])->and(lookupCalls())->toBe(1);
    });

    it('asks at most the budget per run and refuses the rest as not checked', function () {
        $budget = ImportGameIdentities::SERVER_LOOKUPS;
        $lines = [];
        foreach (range(1, $budget + 1) as $n) {
            $trooper = Trooper::factory()->create(['username' => "Budget{$n}.X"]);
            $trooper->forceFill(['primary_section_id' => $this->section->id])->save();
            $lines[] = "Budget{$n}.X, HLL, ?, ".sprintf('%032x', $n);
        }
        MockClient::global([LOOKUP_PROFILE_URL => lookupProfile('xbl')]);

        $result = $this->actingAs($this->leader)->postJson('/game-identities/import/preview', ['text' => implode('
', $lines)])->json('lines');

        expect(lookupCalls())->toBe($budget)
            ->and(collect($result)->last()['lookup'])->toBe('not_checked')
            ->and(collect($result)->where('lookup', 'found')->count())->toBe($budget);
    });
});

describe('the EOS ID', function () {
    it('accepts 32 hex characters starting 0002, stored lower case', function () {
        $format = IdKindFormat::for('EOS ID');

        expect($format->accepts($format->canonical('0002C28E'.str_repeat('A', 24))))->toBeTrue()
            ->and($format->accepts(str_repeat('a', 32)))->toBeFalse()
            ->and($format->accepts('0002'.str_repeat('a', 27)))->toBeFalse();
    });
});

describe('the kinds migration', function () {
    function consoleKindsMigration(): object
    {
        return require database_path('migrations/2026_11_17_100000_add_console_id_kinds_to_hell_let_loose.php');
    }

    it('gives both games PlayStation IDs and Vietnam EOS IDs, once', function () {
        consoleKindsMigration()->up();

        expect(game('hll')->id_kinds)->toBe(['Steam ID', 'Epic ID', 'Xbox ID', 'PlayStation ID'])
            ->and(game('hll-vietnam')->id_kinds)->toBe(['Steam ID', 'Epic ID', 'Xbox ID', 'PlayStation ID', 'EOS ID']);
    });

    it('keeps an Admin\'s kinds and order and appends only what is missing', function () {
        game('hll')->update(['id_kinds' => ['Xbox ID', 'PlayStation ID', 'Steam ID']]);

        consoleKindsMigration()->up();

        expect(game('hll')->id_kinds)->toBe(['Xbox ID', 'PlayStation ID', 'Steam ID']);
    });

    it('takes a kind back off only while no identity uses it', function () {
        TrooperGameIdentity::factory()->create(['trooper_id' => $this->novak->id, 'game_id' => game('hll')->id, 'platform_id' => PSN_ID, 'id_kind' => 'PlayStation ID']);

        consoleKindsMigration()->down();

        expect(game('hll')->id_kinds)->toContain('PlayStation ID')
            ->and(game('hll-vietnam')->id_kinds)->not->toContain('PlayStation ID')->not->toContain('EOS ID');

        consoleKindsMigration()->up();
    });
});
