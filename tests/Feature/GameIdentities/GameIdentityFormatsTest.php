<?php

use App\Commands\GameIdentities\InvalidGameIdentityException;
use App\Commands\GameIdentities\SetTrooperGameIdentity;
use App\Commands\GameIdentities\SetTrooperGameIdentityHandler;
use App\Models\AuditLogEntry;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperGameIdentityRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// #450: an ID is checked against its kind's shape, names and IDs are stored clean, a name that mixes alphabets is
// flagged, and an Admin sees the identities saved before that break these rules.

const FORMATS_STEAM = '76561198012345678';
const FORMATS_EPIC = '0002105c01b748ada8d8cf5b32e70093';

beforeEach(function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '950/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'path' => '950/951/', 'callsign' => '1/A']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->mine = formatsTrooper($this->section, ['username' => 'Ortega.M', 'rank_short' => 'SGT']);
});

function formatsTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function formatsSave(object $test, array $body, string $platform = 'hll'): TestResponse
{
    return $test->actingAs($test->leader)->put("/game-identities/{$test->mine->id}/{$platform}", ['player_name' => 'Ortega', ...$body]);
}

function formatsCaptured(object $test, string $name, string $id): void
{
    PracticeCapturePlayer::query()->create([
        'section_practice_id' => $test->practice->id, 'game_id' => game('hll')->id, 'player_name' => $name, 'platform_id' => $id, 'trooper_id' => null,
    ]);
}

function formatsLink(object $test, string $id): TestResponse
{
    return $test->actingAs($test->leader)->postJson("/rollcall/{$test->section->id}/unmatched/link", [
        'week' => '2026-10-03', 'platform' => 'hll', 'platform_id' => $id, 'trooper_id' => $test->mine->id,
    ]);
}

describe('the shape of an ID', function () {
    it('refuses a Steam ID that is not 17 digits starting 7656119, saying what one looks like, writing nothing', function (string $id) {
        formatsSave($this, ['platform_id' => $id, 'id_kind' => 'Steam ID'])
            ->assertSessionHasErrors(['platform_id' => 'This is not a valid Steam ID. A Steam ID is 17 digits starting 7656119, for example 76561198012345678.']);

        expect(TrooperGameIdentity::query()->count())->toBe(0)->and(AuditLogEntry::query()->count())->toBe(0);
    })->with(['7656119801234567', '765611980123456789', '12345678901234567', 'Ortega']);

    it('saves a valid Steam ID', function () {
        formatsSave($this, ['platform_id' => FORMATS_STEAM, 'id_kind' => 'Steam ID'])->assertSessionHasNoErrors();

        expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe(FORMATS_STEAM);
    });

    it('checks the game\'s default kind when none is sent', function () {
        formatsSave($this, ['platform_id' => '7656'], 'wardogs')->assertSessionHasErrors('platform_id');
        formatsSave($this, ['platform_id' => FORMATS_STEAM], 'wardogs')->assertSessionHasNoErrors();
    });

    it('refuses an Epic ID that is not 32 hex characters', function () {
        formatsSave($this, ['platform_id' => substr(FORMATS_EPIC, 1), 'id_kind' => 'Epic ID'])
            ->assertSessionHasErrors(['platform_id' => 'This is not a valid Epic ID. An Epic ID is 32 characters, the digits 0 to 9 and the letters a to f.']);

        expect(TrooperGameIdentity::query()->count())->toBe(0);
    });

    it('stores an Epic ID typed in upper case lower case, the form CRCON reports, so the capture finds it', function () {
        formatsSave($this, ['platform_id' => strtoupper(FORMATS_EPIC), 'id_kind' => 'Epic ID'])->assertSessionHasNoErrors();

        expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe(FORMATS_EPIC)
            ->and(app(TrooperGameIdentityRepository::class)->findByPlatformId(game('hll'), FORMATS_EPIC)?->trooper_id)->toBe($this->mine->id);
    });

    it('records nothing when the same Epic ID is saved again in upper case', function () {
        formatsSave($this, ['platform_id' => FORMATS_EPIC, 'id_kind' => 'Epic ID']);
        formatsSave($this, ['platform_id' => strtoupper(FORMATS_EPIC), 'id_kind' => 'Epic ID'])->assertSessionHasNoErrors();

        expect(AuditLogEntry::query()->count())->toBe(1);
    });

    it('names the owner when an upper-case Epic ID is already linked to someone else', function () {
        $other = formatsTrooper($this->section, ['username' => 'Hicks.D', 'rank_short' => 'CPL']);
        TrooperGameIdentity::factory()->create(['trooper_id' => $other->id, 'platform_id' => FORMATS_EPIC, 'id_kind' => 'Epic ID']);

        formatsSave($this, ['platform_id' => strtoupper(FORMATS_EPIC), 'id_kind' => 'Epic ID'])
            ->assertSessionHasErrors(['platform_id' => "Already linked to {$other->fresh()->display_name} on Hell Let Loose (WW2)."]);
    });

    it('keeps the free check for a kind with no known shape', function (string $platform, string $kind, string $id) {
        formatsSave($this, ['platform_id' => $id, 'id_kind' => $kind], $platform)->assertSessionHasNoErrors();

        expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe($id);
    })->with([
        'Xbox ID' => ['hll', 'Xbox ID', '2535412345678901'],
        'Arma Reforger Player ID' => ['arma', 'Player ID', 'any value'],
    ]);

    it('follows a kind an Admin adds later by its name', function () {
        game('arma')->update(['id_kinds' => ['Player ID', 'Steam ID']]);

        formatsSave($this, ['platform_id' => '7656', 'id_kind' => 'Steam ID'], 'arma')->assertSessionHasErrors('platform_id');
    });
});

describe('cleaning names and IDs', function () {
    it('stores the name and ID without hidden characters, normalised to NFC and trimmed', function () {
        formatsSave($this, ['player_name' => " \u{200B}Jose\u{0301}\u{202E} Ortega\u{00AD} ", 'platform_id' => "\u{FEFF}7656119801234\u{200D}5678\u{2069}", 'id_kind' => 'Steam ID'])
            ->assertSessionHasNoErrors();

        $identity = TrooperGameIdentity::query()->sole();
        expect($identity->player_name)->toBe("Jos\u{00E9} Ortega")
            ->and($identity->platform_id)->toBe(FORMATS_STEAM);
    });

    it('refuses a name or ID that is only hidden characters, with a message on its field', function () {
        // Tag characters: invisible, and not among those the request's own trimming removes, so they reach the cleaning.
        formatsSave($this, ['player_name' => "\u{E0041}\u{E0042}", 'platform_id' => FORMATS_STEAM])
            ->assertSessionHasErrors(['player_name' => 'The player name is only hidden characters. Type the name as it shows in the game.']);
        formatsSave($this, ['platform_id' => "\u{E0041}\u{E0042}", 'id_kind' => 'Xbox ID'])
            ->assertSessionHasErrors(['platform_id' => 'The ID is only hidden characters. Copy it from the game again.']);

        expect(TrooperGameIdentity::query()->count())->toBe(0)->and(AuditLogEntry::query()->count())->toBe(0);
    });

    it('refuses at the Command too, whatever reaches it', function () {
        $handler = app(SetTrooperGameIdentityHandler::class);

        expect(fn () => $handler->handle($this->leader, new SetTrooperGameIdentity($this->mine->id, game('hll'), "\u{200B}\u{200D}", FORMATS_STEAM)))
            ->toThrow(InvalidGameIdentityException::class, 'The player name is only hidden characters.')
            ->and(fn () => $handler->handle($this->leader, new SetTrooperGameIdentity($this->mine->id, game('hll'), 'Ortega', "\u{FEFF}\u{202E}", idKind: 'Xbox ID')))
            ->toThrow(InvalidGameIdentityException::class, 'The ID is only hidden characters.');
    });

    it('stores a name with markup as text, and the page sends it back unchanged for Vue to escape', function () {
        formatsSave($this, ['player_name' => '<img src=x onerror=alert(1)>[b]Ortega[/b]', 'platform_id' => FORMATS_STEAM])->assertSessionHasNoErrors();

        $this->actingAs($this->leader)->get('/game-identities')->assertInertia(fn (Assert $page) => $page
            ->where('troopers.0.identities.hll.playerName', '<img src=x onerror=alert(1)>[b]Ortega[/b]'));
    });
});

describe('a name that mixes alphabets', function () {
    it('is saved but flagged on the identity, and the page shows the flag', function () {
        formatsSave($this, ['player_name' => "Ort\u{0435}ga", 'platform_id' => FORMATS_STEAM])->assertSessionHasNoErrors();

        expect(TrooperGameIdentity::query()->sole()->name_mixes_scripts)->toBeTrue();
        $this->actingAs($this->leader)->get('/game-identities')->assertInertia(fn (Assert $page) => $page
            ->where('troopers.0.identities.hll.mixedScripts', true));
    });

    it('clears the flag when the name is corrected', function () {
        formatsSave($this, ['player_name' => "Ort\u{0435}ga", 'platform_id' => FORMATS_STEAM]);
        formatsSave($this, ['player_name' => 'Ortega', 'platform_id' => FORMATS_STEAM]);

        expect(TrooperGameIdentity::query()->sole()->name_mixes_scripts)->toBeFalse();
    });

    it('is worked out for a row saved before the flag existed, without writing to it', function () {
        $identity = TrooperGameIdentity::factory()->create(['trooper_id' => $this->mine->id, 'player_name' => "Ort\u{0435}ga"]);
        TrooperGameIdentity::query()->whereKey($identity->id)->update(['name_mixes_scripts' => null]);

        $this->actingAs($this->leader)->get('/game-identities')->assertInertia(fn (Assert $page) => $page
            ->where('troopers.0.identities.hll.mixedScripts', true));

        expect(TrooperGameIdentity::query()->sole()->name_mixes_scripts)->toBeNull();
    });

    it('sends the hint of each kind the server checks, and none for the others', function () {
        $this->actingAs($this->leader)->get('/game-identities')->assertInertia(fn (Assert $page) => $page
            ->where('platforms.0.idHints', [
                'Steam ID' => 'A Steam ID is 17 digits starting 7656119, for example 76561198012345678.',
                'Epic ID' => 'An Epic ID is 32 characters, the digits 0 to 9 and the letters a to f.',
            ])
            ->where('platforms.1.idHints', []));
    });
});

describe('the identities an Admin is asked to check', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create();
        $this->rows = [
            'shape' => TrooperGameIdentity::factory()->create(['player_name' => 'Short', 'platform_id' => '7656']),
            'upper' => TrooperGameIdentity::factory()->create(['player_name' => 'Upper', 'platform_id' => strtoupper(FORMATS_EPIC), 'id_kind' => 'Epic ID']),
            'hidden' => TrooperGameIdentity::factory()->create(['player_name' => "Hid\u{200B}den", 'platform_id' => FORMATS_STEAM]),
            'mixed' => TrooperGameIdentity::factory()->create(['player_name' => "Ort\u{0435}ga", 'platform_id' => '76561198000000002']),
            'fine' => TrooperGameIdentity::factory()->create(['player_name' => 'Fine', 'platform_id' => '76561198000000003']),
            'xbox' => TrooperGameIdentity::factory()->create(['player_name' => 'Box', 'platform_id' => 'anything', 'id_kind' => 'Xbox ID']),
        ];
        $this->before = TrooperGameIdentity::query()->orderBy('id')->get()->map->only(['player_name', 'platform_id', 'id_kind', 'updated_at'])->all();
    });

    it('lists only those that break a rule, with what to check and hidden characters written out', function () {
        $this->actingAs($this->admin)->get('/settings/games')->assertInertia(fn (Assert $page) => $page
            ->has('identitiesToCheck', 4)
            ->where('identitiesToCheck.0.id', $this->rows['shape']->id)
            ->where('identitiesToCheck.0.problems', ['This is not a valid Steam ID. A Steam ID is 17 digits starting 7656119, for example 76561198012345678.'])
            ->where('identitiesToCheck.0.game', 'Hell Let Loose (WW2)')
            ->where('identitiesToCheck.0.idKind', 'Steam ID')
            ->where('identitiesToCheck.0.trooper', $this->rows['shape']->trooper->display_name)
            ->where('identitiesToCheck.1.id', $this->rows['upper']->id)
            ->where('identitiesToCheck.2.playerName', 'Hid[U+200B]den')
            ->where('identitiesToCheck.2.problems', ['The player name has hidden characters or extra spaces.'])
            ->where('identitiesToCheck.3.problems', ['The player name mixes alphabets. Check it against the game.']));
    });

    it('changes and deletes nothing it lists', function () {
        $this->actingAs($this->admin)->get('/settings/games')->assertOk();

        expect(TrooperGameIdentity::query()->orderBy('id')->get()->map->only(['player_name', 'platform_id', 'id_kind', 'updated_at'])->all())->toEqual($this->before);
    });

    it('is sent to an Admin only, never to a leader', function () {
        $this->actingAs($this->leader)->get('/settings/games')->assertInertia(fn (Assert $page) => $page->where('identitiesToCheck', []));
    });

    it('marks which kinds of each game have their shape checked', function () {
        $this->actingAs($this->admin)->get('/settings/games')->assertInertia(fn (Assert $page) => $page
            ->where('games.0.checkedKinds', ['Steam ID', 'Epic ID'])
            ->where('games.1.checkedKinds', []));
    });
});

describe('linking an unmatched player from a capture', function () {
    beforeEach(function () {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
        $this->practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-10-03 00:00:00']);
        $this->practice->forceFill(['captured_at' => '2026-10-08 12:00:00', 'capture_source' => 'crcon', 'server_ref' => 'crcon-1'])->save();
    });

    it('takes the ID the server reported as it is, skipping the shape of the game\'s default kind', function () {
        formatsCaptured($this, 'Ortega', FORMATS_EPIC);

        formatsLink($this, FORMATS_EPIC)->assertOk();

        $identity = TrooperGameIdentity::query()->sole();
        expect($identity->platform_id)->toBe(FORMATS_EPIC)
            ->and($identity->id_kind)->toBe('Steam ID')
            ->and(app(TrooperGameIdentityRepository::class)->findByPlatformId(game('hll'), FORMATS_EPIC)?->id)->toBe($identity->id);
    });

    it('stores an upper-case server ID exactly as reported, so the next capture still matches it', function () {
        formatsCaptured($this, 'Ortega', strtoupper(FORMATS_EPIC));

        formatsLink($this, strtoupper(FORMATS_EPIC))->assertOk();

        expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe(strtoupper(FORMATS_EPIC));
    });

    it('cleans the reported name, and flags one that mixes alphabets', function () {
        formatsCaptured($this, "\u{200B}Ort\u{0435}ga\u{202E}", FORMATS_STEAM);

        formatsLink($this, FORMATS_STEAM)->assertOk();

        $identity = TrooperGameIdentity::query()->sole();
        expect($identity->player_name)->toBe("Ort\u{0435}ga")->and($identity->name_mixes_scripts)->toBeTrue();
    });

    it('takes the ID as the name when nothing of the reported name is left', function () {
        formatsCaptured($this, "\u{200B}", FORMATS_STEAM);

        formatsLink($this, FORMATS_STEAM)->assertOk();

        expect(TrooperGameIdentity::query()->sole()->player_name)->toBe(FORMATS_STEAM);
    });

    it('refuses a reported ID that cleaning would change, rather than store one the capture cannot match', function () {
        formatsCaptured($this, 'Ortega', "7656119801234\u{200B}5678");

        formatsLink($this, "7656119801234\u{200B}5678")
            ->assertStatus(422)
            ->assertJsonPath('message', 'The ID this player was reported with has hidden characters, so it cannot be linked. Mark this trooper by hand.');

        expect(TrooperGameIdentity::query()->count())->toBe(0);
    });
});
