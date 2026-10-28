<?php

use App\Models\Game;
use App\Models\GamePlayerDismissal;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\TelemetryInterface;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\User;

// #431: Hell Let Loose is two games, WW2 (the existing `hll`, renamed) and Vietnam (`hll-vietnam`, new).

function hllSplitMigration(): object
{
    return require database_path('migrations/2026_10_28_140000_split_hell_let_loose_into_ww2_and_vietnam.php');
}

it('renames the existing game to WW2 and adds Vietnam, both with Steam, Epic and Xbox IDs, Vietnam not yet read', function () {
    expect(game('hll')->name)->toBe('Hell Let Loose (WW2)')
        ->and(game('hll')->id_kinds)->toBe(['Steam ID', 'Epic ID', 'Xbox ID'])
        ->and(game('hll')->interface)->toBe(TelemetryInterface::Crcon)
        ->and(game('hll-vietnam')->name)->toBe('Hell Let Loose: Vietnam')
        ->and(game('hll-vietnam')->id_kinds)->toBe(['Steam ID', 'Epic ID', 'Xbox ID'])
        ->and(game('hll-vietnam')->interface)->toBe(TelemetryInterface::None)
        ->and(game('hll-vietnam')->isRetired())->toBeFalse();
});

it('keeps the WW2 slug, identities, captures and dismissals, so past captures and attendance stay with WW2', function () {
    $hll = game('hll');
    $user = User::factory()->create();
    $identity = TrooperGameIdentity::factory()->create(['game_id' => $hll->id, 'id_kind' => 'Steam ID']);
    $captured = PracticeCapturePlayer::query()->create(['section_practice_id' => SectionPractice::factory()->create()->id, 'game_id' => $hll->id, 'player_name' => 'P', 'platform_id' => '1']);
    $dismissed = GamePlayerDismissal::query()->create(['game_id' => $hll->id, 'platform_id' => '2', 'player_name' => 'D', 'dismissed_by' => $user->id]);

    hllSplitMigration()->down();
    hllSplitMigration()->up();

    expect(game('hll')->id)->toBe($hll->id)
        ->and($identity->refresh()->game_id)->toBe($hll->id)
        ->and($captured->refresh()->game_id)->toBe($hll->id)
        ->and($dismissed->refresh()->game_id)->toBe($hll->id);
});

it('can be run twice without a second Vietnam game or a changed WW2', function () {
    hllSplitMigration()->up();

    expect(Game::query()->whereIn('slug', ['hll', 'hll-vietnam'])->count())->toBe(2)
        ->and(game('hll')->name)->toBe('Hell Let Loose (WW2)');
});

it('leaves a Vietnam game an Admin already added, and a WW2 name or kinds an Admin already changed', function () {
    hllSplitMigration()->down();
    game('hll')->update(['name' => 'HLL WW2', 'id_kinds' => ['Steam ID', 'GOG ID']]);
    Game::factory()->create(['slug' => 'hll-vietnam', 'name' => 'HLL Nam', 'id_kinds' => ['Epic ID'], 'interface' => TelemetryInterface::Crcon]);

    hllSplitMigration()->up();

    expect(Game::query()->where('slug', 'hll-vietnam')->count())->toBe(1)
        ->and(game('hll-vietnam')->name)->toBe('HLL Nam')
        ->and(game('hll-vietnam')->id_kinds)->toBe(['Epic ID'])
        ->and(game('hll-vietnam')->interface)->toBe(TelemetryInterface::Crcon)
        ->and(game('hll')->name)->toBe('HLL WW2')
        ->and(game('hll')->id_kinds)->toBe(['Steam ID', 'GOG ID']);
});

it('stops with a clear message, changing nothing, when another game already holds a name it needs', function () {
    hllSplitMigration()->down();
    Game::factory()->create(['slug' => 'nam', 'name' => 'Hell Let Loose: Vietnam']);

    expect(fn () => hllSplitMigration()->up())->toThrow(RuntimeException::class, 'already belongs to the game "nam"')
        ->and(game('hll')->name)->toBe('Hell Let Loose')
        ->and(game('hll')->id_kinds)->toBe(['Steam ID'])
        ->and(Game::query()->where('slug', 'hll-vietnam')->exists())->toBeFalse();
});

it('rolls back to one Hell Let Loose, keeping the WW2 identities linked', function () {
    $identity = TrooperGameIdentity::factory()->create(['game_id' => game('hll')->id, 'id_kind' => 'Steam ID']);

    hllSplitMigration()->down();

    expect(game('hll')->name)->toBe('Hell Let Loose')
        ->and(game('hll')->id_kinds)->toBe(['Steam ID'])
        ->and(Game::query()->where('slug', 'hll-vietnam')->exists())->toBeFalse()
        ->and($identity->refresh()->game_id)->toBe(game('hll')->id);

    hllSplitMigration()->up();

    expect(game('hll')->name)->toBe('Hell Let Loose (WW2)')
        ->and(Game::query()->where('slug', 'hll-vietnam')->exists())->toBeTrue();
});

it('keeps the WW2 kinds on a rollback when an identity already uses one of them', function () {
    TrooperGameIdentity::factory()->create(['game_id' => game('hll')->id, 'id_kind' => 'Epic ID']);

    hllSplitMigration()->down();

    expect(game('hll')->id_kinds)->toBe(['Steam ID', 'Epic ID', 'Xbox ID']);
});

it('refuses a rollback while anything uses the Vietnam game, changing nothing', function () {
    TrooperGameIdentity::factory()->create(['game_id' => game('hll-vietnam')->id, 'id_kind' => 'Epic ID']);

    expect(fn () => hllSplitMigration()->down())->toThrow(RuntimeException::class, 'use the Vietnam game')
        ->and(game('hll')->name)->toBe('Hell Let Loose (WW2)')
        ->and(Game::query()->where('slug', 'hll-vietnam')->exists())->toBeTrue();
});

it('lets one trooper hold an identity on each game, and the same ID value on both is not a collision', function () {
    $trooper = Trooper::factory()->create();
    $epic = '0002105c01b748ada8d8cf5b32e70093';

    TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'game_id' => game('hll')->id, 'platform_id' => $epic, 'id_kind' => 'Epic ID']);
    TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'game_id' => game('hll-vietnam')->id, 'platform_id' => $epic, 'id_kind' => 'Epic ID']);

    expect(TrooperGameIdentity::query()->where('trooper_id', $trooper->id)->count())->toBe(2);
});
