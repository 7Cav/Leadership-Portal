<?php

use App\Models\Game;
use App\Models\SectionPractice;
use App\Models\TelemetryInterface;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// #428 adds `games.id_kinds` and `trooper_game_identities.id_kind` on top of #388's schema, so the #388 migration is
// rolled back and forward below with the #428 one taken off and put back around it.

// #388: the three games move from a hand-kept enum (and `App\Models\GamePlatform`) into the `games` table, and the
// three tables that named a game by their `platform` enum point at it by `game_id` instead.

function gamesMigration(): object
{
    return require database_path('migrations/2026_10_27_100000_create_games_table.php');
}

function idKindsMigration(): object
{
    return require database_path('migrations/2026_10_28_130000_add_id_kinds_to_games.php');
}

/** The schema as it was before #388: the #428 columns come off first, then the games table. */
function rollBackToPlatformEnum(): void
{
    idKindsMigration()->down();
    gamesMigration()->down();
}

function restoreGames(): void
{
    gamesMigration()->up();
    idKindsMigration()->up();
}

it('creates the games with their names, id kinds and interfaces', function () {
    expect(Game::query()->orderBy('id')->get()->map(fn (Game $game) => [$game->slug, $game->name, $game->id_kinds, $game->interface, $game->retired_at])->all())
        ->toBe([
            ['hll', 'Hell Let Loose (WW2)', ['Steam ID', 'Epic ID', 'Xbox ID'], TelemetryInterface::Crcon, null],
            ['arma', 'Arma Reforger', ['Player ID'], TelemetryInterface::BattleMetrics, null],
            ['wardogs', 'WARDOGS', ['Steam ID'], TelemetryInterface::Nightwatch, null],
            ['hll-vietnam', 'Hell Let Loose: Vietnam', ['Steam ID', 'Epic ID', 'Xbox ID'], TelemetryInterface::None, null],
        ]);
});

it('points every existing identity, capture player and dismissal at the game its platform named', function () {
    $trooper = Trooper::factory()->create();
    $user = User::factory()->create();
    $practice = SectionPractice::factory()->create();

    rollBackToPlatformEnum();

    foreach (['hll', 'arma', 'wardogs'] as $platform) {
        DB::table('trooper_game_identities')->insert(['trooper_id' => $trooper->id, 'platform' => $platform, 'player_name' => 'P', 'platform_id' => "id-{$platform}"]);
        DB::table('practice_capture_players')->insert(['section_practice_id' => $practice->id, 'platform' => $platform, 'player_name' => 'C', 'platform_id' => "id-{$platform}"]);
        DB::table('game_player_dismissals')->insert(['platform' => $platform, 'platform_id' => "id-{$platform}", 'player_name' => 'D', 'dismissed_by' => $user->id]);
    }

    restoreGames();

    $slugOf = Game::query()->pluck('slug', 'id');

    foreach (['trooper_game_identities', 'practice_capture_players', 'game_player_dismissals'] as $table) {
        $rows = DB::table($table)->get();

        expect(Schema::hasColumn($table, 'platform'))->toBeFalse()
            ->and($rows)->toHaveCount(3)
            ->and($rows->map(fn ($row) => "id-{$slugOf[$row->game_id]}")->all())->toBe($rows->pluck('platform_id')->all());
    }
});

it('keeps one identity per game identifier, and one per trooper per game', function () {
    $hll = Game::query()->where('slug', 'hll')->firstOrFail();
    $trooper = Trooper::factory()->create();
    DB::table('trooper_game_identities')->insert(['trooper_id' => $trooper->id, 'game_id' => $hll->id, 'id_kind' => 'Steam ID', 'player_name' => 'A', 'platform_id' => '1']);

    expect(fn () => DB::table('trooper_game_identities')->insert(['trooper_id' => Trooper::factory()->create()->id, 'game_id' => $hll->id, 'id_kind' => 'Steam ID', 'player_name' => 'B', 'platform_id' => '1']))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('trooper_game_identities')->insert(['trooper_id' => $trooper->id, 'game_id' => $hll->id, 'id_kind' => 'Steam ID', 'player_name' => 'C', 'platform_id' => '2']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('rolls back to the platform enum and forward again, keeping every row on its game', function () {
    $wardogs = Game::query()->where('slug', 'wardogs')->firstOrFail();
    DB::table('trooper_game_identities')->insert(['trooper_id' => Trooper::factory()->create()->id, 'game_id' => $wardogs->id, 'id_kind' => 'Steam ID', 'player_name' => 'W', 'platform_id' => '7656']);

    rollBackToPlatformEnum();

    expect(Schema::hasTable('games'))->toBeFalse()
        ->and(Schema::hasColumn('trooper_game_identities', 'game_id'))->toBeFalse()
        ->and(DB::table('trooper_game_identities')->value('platform'))->toBe('wardogs');

    restoreGames();

    expect(DB::table('trooper_game_identities')->value('game_id'))->toBe(Game::query()->where('slug', 'wardogs')->value('id'));
});

it('refuses to roll back while a row in any of the three tables uses a game added since, and changes nothing', function () {
    $added = Game::factory()->create(['slug' => 'squad']);
    DB::table('trooper_game_identities')->insert(['trooper_id' => Trooper::factory()->create()->id, 'game_id' => $added->id, 'id_kind' => 'Player ID', 'player_name' => 'S', 'platform_id' => 'eos']);
    DB::table('practice_capture_players')->insert(['section_practice_id' => SectionPractice::factory()->create()->id, 'game_id' => $added->id, 'player_name' => 'S', 'platform_id' => 'eos']);
    DB::table('game_player_dismissals')->insert(['game_id' => $added->id, 'platform_id' => 'eos', 'player_name' => 'S', 'dismissed_by' => User::factory()->create()->id]);

    idKindsMigration()->down();

    expect(fn () => gamesMigration()->down())->toThrow(RuntimeException::class, '3 row(s) use a game the platform enum cannot hold (squad)');

    expect(Schema::hasTable('games'))->toBeTrue()
        ->and(DB::table('trooper_game_identities')->value('game_id'))->toBe($added->id);
});

it('refuses to move a row whose platform names no game, before changing anything', function () {
    rollBackToPlatformEnum();
    // SQLite does not enforce the enum; a non-strict MySQL stores '' for a bad value. Either way the backfill would
    // leave `game_id` null.
    DB::table('game_player_dismissals')->insert(['platform' => '', 'platform_id' => '1', 'player_name' => 'D', 'dismissed_by' => User::factory()->create()->id]);

    expect(fn () => gamesMigration()->up())->toThrow(RuntimeException::class, 'Cannot move game_player_dismissals onto the games table: 1 row(s)');

    expect(Schema::hasTable('games'))->toBeFalse()
        ->and(Schema::hasColumn('trooper_game_identities', 'platform'))->toBeTrue();

    DB::table('game_player_dismissals')->delete();
    restoreGames();
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'a strict MySQL refuses the bad enum value at the insert');

it('refuses at the database to delete a game something uses', function () {
    TrooperGameIdentity::factory()->create(['game_id' => game('hll')->id]);

    expect(fn () => DB::table('games')->where('slug', 'hll')->delete())->toThrow(QueryException::class);
});

// #428: a game's one id label becomes a list of kinds, and an identity remembers the kind it was entered as.

it('turns each game\'s id label into its one kind and gives every identity its game\'s first kind', function () {
    idKindsMigration()->down();

    expect(Schema::hasColumn('games', 'id_label'))->toBeTrue()
        ->and(Schema::hasColumn('games', 'id_kinds'))->toBeFalse()
        ->and(Schema::hasColumn('trooper_game_identities', 'id_kind'))->toBeFalse()
        ->and(DB::table('games')->where('slug', 'arma')->value('id_label'))->toBe('Player ID');

    foreach (['hll', 'arma'] as $slug) {
        DB::table('trooper_game_identities')->insert(['trooper_id' => Trooper::factory()->create()->id, 'game_id' => game($slug)->id, 'player_name' => 'P', 'platform_id' => "id-{$slug}"]);
    }

    idKindsMigration()->up();

    expect(Schema::hasColumn('games', 'id_label'))->toBeFalse()
        ->and(game('hll')->id_kinds)->toBe(['Steam ID'])
        ->and(DB::table('trooper_game_identities')->orderBy('id')->pluck('id_kind')->all())->toBe(['Steam ID', 'Player ID']);
});

it('rolls back to the first kind as the game\'s label, keeping every identity linked', function () {
    game('hll')->update(['id_kinds' => ['Steam ID', 'Epic ID', 'Xbox ID']]);
    TrooperGameIdentity::factory()->create(['game_id' => game('hll')->id, 'id_kind' => 'Epic ID']);

    idKindsMigration()->down();

    expect(DB::table('games')->where('slug', 'hll')->value('id_label'))->toBe('Steam ID')
        ->and(DB::table('trooper_game_identities')->count())->toBe(1);

    idKindsMigration()->up();

    expect(game('hll')->id_kinds)->toBe(['Steam ID']);
});

it('keeps one owner per id on a game whichever kind either was entered as', function () {
    $hll = game('hll');
    $hll->update(['id_kinds' => ['Steam ID', 'Epic ID']]);
    DB::table('trooper_game_identities')->insert(['trooper_id' => Trooper::factory()->create()->id, 'game_id' => $hll->id, 'id_kind' => 'Steam ID', 'player_name' => 'A', 'platform_id' => '0002105c']);

    expect(fn () => DB::table('trooper_game_identities')->insert(['trooper_id' => Trooper::factory()->create()->id, 'game_id' => $hll->id, 'id_kind' => 'Epic ID', 'player_name' => 'B', 'platform_id' => '0002105c']))
        ->toThrow(UniqueConstraintViolationException::class);
});
