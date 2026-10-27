<?php

use App\Models\Game;
use App\Models\SectionPractice;
use App\Models\TelemetryInterface;
use App\Models\Trooper;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// #388: the three games move from a hand-kept enum (and `App\Models\GamePlatform`) into the `games` table, and the
// three tables that named a game by their `platform` enum point at it by `game_id` instead.

function gamesMigration(): object
{
    return require database_path('migrations/2026_10_27_100000_create_games_table.php');
}

it('creates the three current games with their names, id labels and interfaces', function () {
    expect(Game::query()->orderBy('id')->get()->map(fn (Game $game) => [$game->slug, $game->name, $game->id_label, $game->interface, $game->retired_at])->all())
        ->toBe([
            ['hll', 'Hell Let Loose', 'Steam ID', TelemetryInterface::Crcon, null],
            ['arma', 'Arma Reforger', 'Player ID', TelemetryInterface::BattleMetrics, null],
            ['wardogs', 'WARDOGS', 'Steam ID', TelemetryInterface::Nightwatch, null],
        ]);
});

it('points every existing identity, capture player and dismissal at the game its platform named', function () {
    $trooper = Trooper::factory()->create();
    $user = User::factory()->create();
    $practice = SectionPractice::factory()->create();

    gamesMigration()->down();

    foreach (['hll', 'arma', 'wardogs'] as $platform) {
        DB::table('trooper_game_identities')->insert(['trooper_id' => $trooper->id, 'platform' => $platform, 'player_name' => 'P', 'platform_id' => "id-{$platform}"]);
        DB::table('practice_capture_players')->insert(['section_practice_id' => $practice->id, 'platform' => $platform, 'player_name' => 'C', 'platform_id' => "id-{$platform}"]);
        DB::table('game_player_dismissals')->insert(['platform' => $platform, 'platform_id' => "id-{$platform}", 'player_name' => 'D', 'dismissed_by' => $user->id]);
    }

    gamesMigration()->up();

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
    DB::table('trooper_game_identities')->insert(['trooper_id' => $trooper->id, 'game_id' => $hll->id, 'player_name' => 'A', 'platform_id' => '1']);

    expect(fn () => DB::table('trooper_game_identities')->insert(['trooper_id' => Trooper::factory()->create()->id, 'game_id' => $hll->id, 'player_name' => 'B', 'platform_id' => '1']))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('trooper_game_identities')->insert(['trooper_id' => $trooper->id, 'game_id' => $hll->id, 'player_name' => 'C', 'platform_id' => '2']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('rolls back to the platform enum and forward again, keeping every row on its game', function () {
    $wardogs = Game::query()->where('slug', 'wardogs')->firstOrFail();
    DB::table('trooper_game_identities')->insert(['trooper_id' => Trooper::factory()->create()->id, 'game_id' => $wardogs->id, 'player_name' => 'W', 'platform_id' => '7656']);

    gamesMigration()->down();

    expect(Schema::hasTable('games'))->toBeFalse()
        ->and(Schema::hasColumn('trooper_game_identities', 'game_id'))->toBeFalse()
        ->and(DB::table('trooper_game_identities')->value('platform'))->toBe('wardogs');

    gamesMigration()->up();

    expect(DB::table('trooper_game_identities')->value('game_id'))->toBe(Game::query()->where('slug', 'wardogs')->value('id'));
});

it('refuses to roll back while a row uses a game added since, and changes nothing', function () {
    $added = Game::factory()->create(['slug' => 'squad']);
    DB::table('trooper_game_identities')->insert(['trooper_id' => Trooper::factory()->create()->id, 'game_id' => $added->id, 'player_name' => 'S', 'platform_id' => 'eos']);

    expect(fn () => gamesMigration()->down())->toThrow(RuntimeException::class, '1 row(s) use a game the platform enum cannot hold (squad)');

    expect(Schema::hasTable('games'))->toBeTrue()
        ->and(DB::table('trooper_game_identities')->value('game_id'))->toBe($added->id);
});
