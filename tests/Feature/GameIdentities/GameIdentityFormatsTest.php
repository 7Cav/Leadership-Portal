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

// #450: an ID is checked against its kind's shape and stored clean, and an Admin sees the identities saved before that
// break these rules.

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
    return $test->actingAs($test->leader)->put("/game-identities/{$test->mine->id}/{$platform}", [...$body]);
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

    it('lets an older identity whose ID does not fit its kind be saved again, and checks it once the ID or kind changes', function () {
        // An Epic player linked from the unmatched list before the kinds were checked: stored under the first kind.
        TrooperGameIdentity::factory()->create(['trooper_id' => $this->mine->id, 'platform_id' => FORMATS_EPIC, 'id_kind' => 'Steam ID']);

        formatsSave($this, ['platform_id' => FORMATS_EPIC, 'id_kind' => 'Steam ID'])->assertSessionHasNoErrors();

        $identity = TrooperGameIdentity::query()->sole();
        expect($identity->platform_id)->toBe(FORMATS_EPIC)
            ->and($identity->id_kind)->toBe('Steam ID');

        formatsSave($this, ['platform_id' => '0002105c01b748ada8d8cf5b32e70094', 'id_kind' => 'Steam ID'])
            ->assertSessionHasErrors('platform_id');
        formatsSave($this, ['platform_id' => FORMATS_EPIC, 'id_kind' => 'Epic ID'])->assertSessionHasNoErrors();

        expect(TrooperGameIdentity::query()->sole()->id_kind)->toBe('Epic ID');
    });

    it('follows a kind an Admin adds later by its name', function () {
        game('arma')->update(['id_kinds' => ['Player ID', 'Steam ID']]);

        formatsSave($this, ['platform_id' => '7656', 'id_kind' => 'Steam ID'], 'arma')->assertSessionHasErrors('platform_id');
    });
});

describe('cleaning IDs', function () {
    it('stores the ID without hidden characters and trimmed', function () {
        formatsSave($this, ['platform_id' => "\u{FEFF}7656119801234\u{200D}5678\u{2069}", 'id_kind' => 'Steam ID'])
            ->assertSessionHasNoErrors();

        expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe(FORMATS_STEAM);
    });

    it('refuses an ID that is only hidden characters, with a message on its field', function () {
        // Tag characters: invisible, and not among those the request's own trimming removes, so they reach the cleaning.
        formatsSave($this, ['platform_id' => "\u{E0041}\u{E0042}", 'id_kind' => 'Xbox ID'])
            ->assertSessionHasErrors(['platform_id' => 'The ID is only hidden characters. Copy it from the game again.']);

        expect(TrooperGameIdentity::query()->count())->toBe(0)->and(AuditLogEntry::query()->count())->toBe(0);
    });

    it('refuses at the Command too, whatever reaches it', function () {
        $handler = app(SetTrooperGameIdentityHandler::class);

        expect(fn () => $handler->handle($this->leader, new SetTrooperGameIdentity($this->mine->id, game('hll'), "\u{FEFF}\u{202E}", idKind: 'Xbox ID')))
            ->toThrow(InvalidGameIdentityException::class, 'The ID is only hidden characters.');
    });
});

describe('the ID hints', function () {
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
            'shape' => TrooperGameIdentity::factory()->create(['platform_id' => '7656']),
            'upper' => TrooperGameIdentity::factory()->create(['platform_id' => strtoupper(FORMATS_EPIC), 'id_kind' => 'Epic ID']),
            'hidden' => TrooperGameIdentity::factory()->create(['platform_id' => "any\u{200B}thing", 'id_kind' => 'Xbox ID']),
            'fine' => TrooperGameIdentity::factory()->create(['platform_id' => '76561198000000003']),
            'xbox' => TrooperGameIdentity::factory()->create(['platform_id' => 'anything', 'id_kind' => 'Xbox ID']),
        ];
        $this->before = TrooperGameIdentity::query()->orderBy('id')->get()->map->only(['platform_id', 'id_kind', 'updated_at'])->all();
    });

    it('lists only those that break a rule, with what to check and hidden characters written out', function () {
        $this->actingAs($this->admin)->get('/settings/games')->assertInertia(fn (Assert $page) => $page
            ->has('identitiesToCheck', 3)
            ->where('identitiesToCheck.0.id', $this->rows['shape']->id)
            ->where('identitiesToCheck.0.problems', ['This is not a valid Steam ID. A Steam ID is 17 digits starting 7656119, for example 76561198012345678.'])
            ->where('identitiesToCheck.0.game', 'Hell Let Loose (WW2)')
            ->where('identitiesToCheck.0.idKind', 'Steam ID')
            ->where('identitiesToCheck.0.trooper', $this->rows['shape']->trooper->display_name)
            ->where('identitiesToCheck.1.id', $this->rows['upper']->id)
            ->where('identitiesToCheck.2.platformId', 'any[U+200B]thing')
            ->where('identitiesToCheck.2.problems', ['The ID has hidden characters or extra spaces.'])
            ->missing('identitiesToCheck.0.playerName'));
    });

    it('changes and deletes nothing it lists', function () {
        $this->actingAs($this->admin)->get('/settings/games')->assertOk();

        expect(TrooperGameIdentity::query()->orderBy('id')->get()->map->only(['platform_id', 'id_kind', 'updated_at'])->all())->toEqual($this->before);
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

    it('takes the ID the server reported as it is, stored as the first of the game\'s kinds whose shape it has, else the default', function (string $id, string $kind) {
        formatsCaptured($this, 'Ortega', $id);

        formatsLink($this, $id)->assertOk();

        $identity = TrooperGameIdentity::query()->sole();
        expect($identity->platform_id)->toBe($id)
            ->and($identity->id_kind)->toBe($kind)
            ->and(app(TrooperGameIdentityRepository::class)->findByPlatformId(game('hll'), $id)?->id)->toBe($identity->id);
    })->with([
        'a Steam ID' => [FORMATS_STEAM, 'Steam ID'],
        'an Epic ID' => [FORMATS_EPIC, 'Epic ID'],
        // A BattleMetrics player number, or an Xbox ID: no kind's shape fits, so the default, and nothing is refused.
        'an ID of no known shape' => ['1234567890', 'Steam ID'],
    ]);

    it('lists no shape problem for an Epic ID linked from a capture', function () {
        formatsCaptured($this, 'Ortega', FORMATS_EPIC);

        formatsLink($this, FORMATS_EPIC)->assertOk();

        expect(TrooperGameIdentity::query()->sole()->problems())->toBe([]);
    });

    it('stores an upper-case server ID exactly as reported, so the next capture still matches it', function () {
        formatsCaptured($this, 'Ortega', strtoupper(FORMATS_EPIC));

        formatsLink($this, strtoupper(FORMATS_EPIC))->assertOk();

        expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe(strtoupper(FORMATS_EPIC));
    });

    it('refuses a reported ID that cleaning would change, rather than store one the capture cannot match', function () {
        formatsCaptured($this, 'Ortega', "7656119801234\u{200B}5678");

        formatsLink($this, "7656119801234\u{200B}5678")
            ->assertStatus(422)
            ->assertJsonPath('message', 'The ID this player was reported with has hidden characters, so it cannot be linked. Mark this trooper by hand.');

        expect(TrooperGameIdentity::query()->count())->toBe(0);
    });
});
