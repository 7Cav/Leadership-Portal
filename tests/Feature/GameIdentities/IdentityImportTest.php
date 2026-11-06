<?php

use App\Commands\GameIdentities\IdentityImportException;
use App\Commands\GameIdentities\IdentityImportParser;
use App\Commands\GameIdentities\ImportGameIdentities;
use App\Integration\Steam\SteamApi;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// Bulk import of game identities from a text file: the parse, each refusal, the scope, the replace tick, the one
// audited batch, and the Steam check (faked with Saloon's MockClient; nothing here reaches Steam).

const IMPORT_STEAM_URL = 'api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/*';
const STEAM_A = '76561198000000001';
const STEAM_B = '76561198000000002';
const STEAM_C = '76561198000000003';

beforeEach(function () {
    config(['services.steam.web_api_key' => '']);
    app()->forgetInstance(SteamApi::class);

    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '950/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'path' => '950/951/', 'callsign' => '1/A']);
    $this->other = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'path' => '950/952/']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->novak = importTrooper($this->section, 'Novak.E');
    $this->ortega = importTrooper($this->section, 'Ortega.M');
    $this->outside = importTrooper($this->other, 'Nakamura.T');
    game('hll')->update(['id_kinds' => ['Steam ID', 'Epic ID', 'Xbox ID']]);
});

function importTrooper(Unit $section, string $username, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create(['username' => $username, 'rank_short' => 'SGT', ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function previewImport(object $test, string $text, bool $replace = false, ?User $as = null): TestResponse
{
    return $test->actingAs($as ?? $test->leader)->postJson('/game-identities/import/preview', ['text' => $text, 'replace' => $replace]);
}

/** @return array<int, array<string, mixed>> the preview's lines by line number */
function previewLines(object $test, string $text, bool $replace = false): array
{
    return collect(previewImport($test, $text, $replace)->assertOk()->json('lines'))->keyBy('line')->all();
}

function runImport(object $test, string $text, array $expected, bool $replace = false): TestResponse
{
    return $test->actingAs($test->leader)->post('/game-identities/import', ['text' => $text, 'replace' => $replace, 'expected' => $expected]);
}

describe('parser', function () {
    it('reads commas, tabs, quotes, a header, comments, blank lines and a byte order mark', function () {
        $rows = (new IdentityImportParser)->parse("\u{FEFF}trooper, game, ID kind, ID\n# a comment\n\nNovak.E, HLL, Steam ID, ".STEAM_A."\r\nOrtega.M\tarma\t\tabc\n\"Dube.R\",\"Hell Let Loose (WW2)\",".STEAM_B.",\n");

        expect($rows)->toBe([
            ['line' => 4, 'cells' => ['Novak.E', 'HLL', 'Steam ID', STEAM_A]],
            ['line' => 5, 'cells' => ['Ortega.M', 'arma', '', 'abc']],
            ['line' => 6, 'cells' => ['Dube.R', 'Hell Let Loose (WW2)', STEAM_B]],
        ]);
    });

    it('strips hidden and control characters from every column', function () {
        $rows = (new IdentityImportParser)->parse("Novak.E\u{200B}, HLL\x07, ".STEAM_A."\u{FEFF}");

        expect($rows[0]['cells'])->toBe(['Novak.E', 'HLL', STEAM_A]);
    });

    it('refuses text that is too large, has too many lines, is binary or holds no identity', function (string $text, string $message) {
        expect(fn () => (new IdentityImportParser)->parse($text))->toThrow(IdentityImportException::class, $message);
    })->with([
        'too large' => [str_repeat('a', IdentityImportParser::MAX_BYTES + 1), 'larger than 256 KB'],
        'too many lines' => [str_repeat("Novak.E, HLL, 1\n", IdentityImportParser::MAX_LINES + 1), 'more than 500'],
        'binary' => ["Novak.E\0, HLL", 'not a text file'],
        'not UTF-8' => ["Novak.E, HLL, \xC3\x28", 'not a text file'],
        'only comments' => ["# nothing\n\n", 'no identities'],
    ]);
});

it('previews each line: add, change, unchanged and skip, writing nothing', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'game_id' => game('hll')->id, 'platform_id' => STEAM_B, 'id_kind' => 'Steam ID']);
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->novak->id, 'game_id' => game('arma')->id, 'platform_id' => 'old-arma', 'id_kind' => game('arma')->defaultIdKind()]);
    $hll = game('hll')->abbreviation;

    $lines = previewLines($this, "Novak.E, {$hll}, , ".STEAM_A."\nOrtega.M, hell let loose (ww2), steam id, ".STEAM_B."\nSGT Novak.E, arma, , new-arma");

    expect($lines[1])->toMatchArray(['status' => 'add', 'trooper' => 'SGT Novak.E', 'game' => 'Hell Let Loose (WW2)', 'idKind' => 'Steam ID', 'platformId' => STEAM_A, 'steam' => 'not_checked'])
        ->and($lines[2])->toMatchArray(['status' => 'unchanged', 'idKind' => 'Steam ID'])
        ->and($lines[3])->toMatchArray(['status' => 'skip', 'current' => 'old-arma'])
        ->and($lines[3]['reason'])->toContain('Replace existing identities')
        ->and(previewLines($this, 'Novak.E, arma, , new-arma', replace: true)[1])->toMatchArray(['status' => 'change', 'current' => 'old-arma'])
        ->and(TrooperGameIdentity::query()->count())->toBe(2)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('refuses each line it cannot save, saying why', function (Closure $setup, string $line, string $reason) {
    $setup($this);

    $lines = previewLines($this, $line);

    expect(end($lines))->toMatchArray(['status' => 'refused'])->and(end($lines)['reason'])->toContain($reason);
})->with([
    'unknown trooper' => [fn () => null, 'Nobody.X, HLL, '.STEAM_A, 'No trooper is named Nobody.X'],
    'out of scope' => [fn () => null, 'Nakamura.T, HLL, '.STEAM_A, 'not in a unit you can change'],
    'not serving' => [fn ($t) => $t->novak->update(['status' => TrooperStatus::Inactive]), 'Novak.E, HLL, '.STEAM_A, 'is not serving'],
    'unknown game' => [fn () => null, 'Novak.E, Tetris, '.STEAM_A, 'No game is called Tetris'],
    'retired game' => [fn () => game('wardogs')->update(['retired_at' => now()]), 'Novak.E, wardogs, abc', 'is retired'],
    'kind not used' => [fn () => null, 'Novak.E, HLL, PlayStation ID, abc', 'does not use PlayStation ID'],
    'bad shape' => [fn () => null, 'Novak.E, HLL, Steam ID, 12345', 'not a valid Steam ID'],
    'empty ID' => [fn () => null, "Novak.E, HLL, \u{200B}", 'The ID is empty'],
    'wrong columns' => [fn () => null, 'Novak.E, HLL', 'Expected trooper, game, ID kind and ID'],
    'held by another' => [fn ($t) => TrooperGameIdentity::factory()->create(['trooper_id' => $t->ortega->id, 'game_id' => game('hll')->id, 'platform_id' => STEAM_A, 'id_kind' => 'Steam ID']), 'Novak.E, HLL, '.STEAM_A, 'Already linked to SGT Ortega.M on Hell Let Loose (WW2)'],
    'same trooper and game twice' => [fn () => null, 'Novak.E, HLL, '.STEAM_A."\nNovak.E, HLL, ".STEAM_B, 'Line 1 already gives SGT Novak.E an identity'],
    'same ID twice' => [fn () => null, 'Novak.E, HLL, '.STEAM_A."\nOrtega.M, HLL, ".STEAM_A, 'Line 1 already gives this ID to SGT Novak.E'],
]);

it('imports the lines it would add or change in one audited batch, marked imported', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->novak->id, 'game_id' => game('arma')->id, 'platform_id' => 'old-arma', 'id_kind' => game('arma')->defaultIdKind()]);
    $text = 'Novak.E, HLL, '.STEAM_A."\nOrtega.M, HLL, Epic ID, ".str_repeat('AB', 16)."\nNovak.E, arma, , new-arma\nNobody.X, HLL, ".STEAM_C;

    runImport($this, $text, [1, 2, 3], replace: true)->assertSessionHasNoErrors()->assertSessionHas('notice', 'Imported 3 identities.');

    $rows = TrooperGameIdentity::query()->orderBy('id')->get();
    expect($rows->pluck('platform_id')->all())->toBe(['new-arma', STEAM_A, str_repeat('ab', 16)])
        ->and($rows->pluck('source')->map->value->unique()->all())->toBe(['imported'])
        ->and(AuditLogEntry::query()->sole()->action)->toBe('game_identity.imported');
});

it('leaves an existing identity alone without the replace tick', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->novak->id, 'game_id' => game('arma')->id, 'platform_id' => 'old-arma', 'id_kind' => game('arma')->defaultIdKind()]);

    runImport($this, "Novak.E, arma, , new-arma\nOrtega.M, HLL, ".STEAM_A, [2])->assertSessionHasNoErrors();

    expect(TrooperGameIdentity::query()->where('trooper_id', $this->novak->id)->sole()->platform_id)->toBe('old-arma')
        ->and(TrooperGameIdentity::query()->where('trooper_id', $this->ortega->id)->sole()->platform_id)->toBe(STEAM_A);
});

it('refuses the whole import when the lines differ from the preview, writing nothing', function () {
    $text = 'Novak.E, HLL, '.STEAM_A."\nOrtega.M, HLL, ".STEAM_B;
    // Someone else takes Ortega's ID after the preview showed both lines as added.
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->outside->id, 'game_id' => game('hll')->id, 'platform_id' => STEAM_B, 'id_kind' => 'Steam ID']);

    runImport($this, $text, [1, 2])->assertSessionHasErrors(['text' => 'The identities changed since the preview. Preview the file again.']);

    expect(TrooperGameIdentity::query()->count())->toBe(1)->and(AuditLogEntry::query()->count())->toBe(0);
});

it('refuses an import with nothing to save', function () {
    runImport($this, 'Nobody.X, HLL, '.STEAM_A, [1])->assertSessionHasErrors(['text' => 'There is nothing to import: every line is unchanged, skipped or refused.']);
});

it('is refused to a user who can change nobody, and offered to a leader and an Admin', function () {
    $nobody = User::factory()->serving()->create();

    previewImport($this, 'Novak.E, HLL, '.STEAM_A, as: $nobody)->assertForbidden();
    $this->actingAs($nobody)->post('/game-identities/import', ['text' => 'Novak.E, HLL, '.STEAM_A, 'expected' => [1]])->assertForbidden();
    $this->actingAs($nobody)->get('/game-identities')->assertInertia(fn (Assert $page) => $page->where('canImport', false));
    $this->actingAs($this->leader)->get('/game-identities')->assertInertia(fn (Assert $page) => $page->where('canImport', true));

    $admin = User::factory()->serving()->create(['is_admin' => true]);
    expect(collect(previewImport($this, 'Nakamura.T, HLL, '.STEAM_A, as: $admin)->assertOk()->json('lines'))->sole()['status'])->toBe('add');
});

it('validates the request: text required and at most 256 KB', function () {
    previewImport($this, '')->assertUnprocessable()->assertJsonValidationErrors(['text' => 'Choose a file or paste the identities.']);
    previewImport($this, str_repeat('a', IdentityImportParser::MAX_BYTES + 1))->assertUnprocessable()->assertJsonValidationErrors(['text' => 'larger than 256 KB']);
});

describe('Steam', function () {
    beforeEach(function () {
        config(['services.steam.web_api_key' => 'test-steam-key']);
        app()->forgetInstance(SteamApi::class);
    });

    it('checks each new Steam ID and refuses one Steam does not know', function () {
        MockClient::global([IMPORT_STEAM_URL => fn ($request) => MockResponse::make(['response' => ['players' => $request->query()->get('steamids') === STEAM_A ? [['steamid' => STEAM_A]] : []]])]);

        $lines = previewLines($this, 'Novak.E, HLL, '.STEAM_A."\nOrtega.M, HLL, ".STEAM_B);

        expect($lines[1])->toMatchArray(['status' => 'add', 'steam' => 'checked'])
            ->and($lines[2])->toMatchArray(['status' => 'refused', 'reason' => 'Steam has no account with this ID.']);
    });

    it('marks Steam IDs not checked when Steam does not answer, asking only once', function () {
        $mock = MockClient::global([IMPORT_STEAM_URL => MockResponse::make('boom', 500)]);

        $lines = previewLines($this, 'Novak.E, HLL, '.STEAM_A."\nOrtega.M, HLL, ".STEAM_B);

        expect(array_column($lines, 'steam'))->toBe(['not_checked', 'not_checked'])
            ->and(array_column($lines, 'status'))->toBe(['add', 'add']);
        $mock->assertSentCount(1);
    });

    it('asks Steam about at most the budget, the rest not checked', function () {
        $mock = MockClient::global([IMPORT_STEAM_URL => fn ($request) => MockResponse::make(['response' => ['players' => [['steamid' => $request->query()->get('steamids')]]]])]);
        $text = collect(range(1, ImportGameIdentities::STEAM_LOOKUPS + 2))->map(function (int $n) {
            importTrooper($this->section, "Bulk.{$n}");

            return "Bulk.{$n}, HLL, ".(76561198100000000 + $n);
        })->implode("\n");

        $lines = previewLines($this, $text);

        expect(collect($lines)->countBy('steam')->all())->toBe(['checked' => ImportGameIdentities::STEAM_LOOKUPS, 'not_checked' => 2]);
        $mock->assertSentCount(ImportGameIdentities::STEAM_LOOKUPS);
    });
});

it('passes player names to the import untouched by the request text cleaner', function (string $text) {
    $seen = null;
    $this->mock(ImportGameIdentities::class)->shouldReceive('preview')->once()
        ->andReturnUsing(function ($actor, string $received) use (&$seen) {
            $seen = $received;

            return [];
        });

    previewImport($this, $text)->assertOk();

    expect($seen)->toBe($text);
})->with([
    'only a Hangul filler' => ["Novak.E, HLL, \u{3164}"],
    'a right-to-left mark' => ["Novak.E, HLL, Name\u{200F}x"],
]);
