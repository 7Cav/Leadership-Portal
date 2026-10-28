<?php

use App\Models\Attendance;
use App\Models\Game;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperGameIdentityRepository\Eloquent as TrooperGameIdentityEloquent;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Story 7.1 / AD-19, AD-22: the portal-owned link between a trooper and their in-game identity.

function tgiRaw(array $overrides = []): array
{
    return array_merge([
        'trooper_id' => Trooper::factory()->create()->id,
        'game_id' => game('hll')->id,
        'player_name' => 'Maverick',
        'platform_id' => '76561198000000001',
        'id_kind' => 'Steam ID',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('creates the table with the identity columns', function () {
    expect(Schema::hasColumns('trooper_game_identities', [
        'id', 'trooper_id', 'game_id', 'player_name', 'platform_id', 'id_kind', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('refuses a second row for the same (game_id, platform_id) at the database', function () {
    DB::table('trooper_game_identities')->insert(tgiRaw());

    expect(fn () => DB::table('trooper_game_identities')->insert(tgiRaw()))->toThrow(QueryException::class);
});

it('allows the same platform_id in a different game', function () {
    DB::table('trooper_game_identities')->insert(tgiRaw());
    DB::table('trooper_game_identities')->insert(tgiRaw(['game_id' => game('arma')->id]));

    expect(DB::table('trooper_game_identities')->count())->toBe(2);
});

it('accepts a wardogs identity alongside the same steam id linked for hll', function () {
    DB::table('trooper_game_identities')->insert(tgiRaw());
    DB::table('trooper_game_identities')->insert(tgiRaw(['game_id' => game('wardogs')->id]));

    expect(DB::table('trooper_game_identities')->count())->toBe(2);
});

it('refuses a second identity for one trooper in one game at the database', function () {
    $trooper = Trooper::factory()->create();
    DB::table('trooper_game_identities')->insert(tgiRaw(['trooper_id' => $trooper->id]));

    expect(fn () => DB::table('trooper_game_identities')->insert(
        tgiRaw(['trooper_id' => $trooper->id, 'platform_id' => '76561198000000002'])
    ))->toThrow(QueryException::class);
});

it('refuses a game that does not exist', function () {
    expect(fn () => DB::table('trooper_game_identities')->insert(tgiRaw(['game_id' => 999999])))
        ->toThrow(QueryException::class);
});

it('links a trooper and finds the link by platform id', function () {
    $trooper = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);

    $link = $repo->link($trooper->id, game('hll'), 'Maverick', '76561198000000001', 'Steam ID');

    expect($link->trooper_id)->toBe($trooper->id)
        ->and($link->game_id)->toBe(game('hll')->id)
        ->and($repo->findByPlatformId(game('hll'), '76561198000000001')?->is($link))->toBeTrue()
        ->and($repo->findByPlatformId(game('arma'), '76561198000000001'))->toBeNull();
});

it('lists a trooper identities, one per platform', function () {
    $trooper = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link($trooper->id, game('hll'), 'Maverick', '1', 'Steam ID');
    $repo->link($trooper->id, game('arma'), 'Mav', '2', 'Player ID');
    $repo->link(Trooper::factory()->create()->id, game('hll'), 'Other', '3', 'Steam ID');

    expect($repo->forTrooper($trooper->id)->pluck('game.slug')->all())
        ->toBe(['hll', 'arma']);
});

it('replaces the trooper own identity on a platform instead of adding a duplicate', function () {
    $trooper = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);
    $first = $repo->link($trooper->id, game('hll'), 'Maverick', '1', 'Steam ID');

    $second = $repo->link($trooper->id, game('hll'), 'Maverick2', '2', 'Steam ID');

    expect(TrooperGameIdentity::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->platform_id)->toBe('2')
        ->and($second->player_name)->toBe('Maverick2');
});

it('is idempotent when the same trooper re-links the same player', function () {
    $trooper = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link($trooper->id, game('hll'), 'Maverick', '1', 'Steam ID');
    $repo->link($trooper->id, game('hll'), 'Renamed', '1', 'Steam ID');

    expect(TrooperGameIdentity::query()->sole()->player_name)->toBe('Renamed');
});

it('rejects linking a platform id already held by another trooper and leaves the link intact', function () {
    $a = Trooper::factory()->create();
    $b = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link($a->id, game('hll'), 'Maverick', '1', 'Steam ID');

    expect(fn () => $repo->link($b->id, game('hll'), 'Thief', '1', 'Steam ID'))
        ->toThrow(GameIdentityLinkException::class);

    $row = TrooperGameIdentity::query()->sole();
    expect($row->trooper_id)->toBe($a->id)->and($row->player_name)->toBe('Maverick');
});

it('does not replace trooper B existing identity when the claim on A fails', function () {
    $a = Trooper::factory()->create();
    $b = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link($a->id, game('hll'), 'Maverick', '1', 'Steam ID');
    $repo->link($b->id, game('hll'), 'Goose', '2', 'Steam ID');

    expect(fn () => $repo->link($b->id, game('hll'), 'Goose', '1', 'Steam ID'))
        ->toThrow(GameIdentityLinkException::class);

    expect($repo->forTrooper($b->id)->sole()->platform_id)->toBe('2');
});

it('unlinks without touching attendance rows', function () {
    $trooper = Trooper::factory()->create();
    $attendance = Attendance::factory()->create(['trooper_id' => $trooper->id]);
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link($trooper->id, game('hll'), 'Maverick', '1', 'Steam ID');

    expect($repo->unlink($trooper->id, game('hll')))->toBeTrue()
        ->and($repo->unlink($trooper->id, game('hll')))->toBeFalse()
        ->and(TrooperGameIdentity::query()->count())->toBe(0)
        ->and($attendance->fresh()->only(['status', 'source', 'rollcall']))->toBe($attendance->only(['status', 'source', 'rollcall']));
});

it('translates a concurrent claim by another trooper into the domain exception', function () {
    $a = Trooper::factory()->create();
    $b = Trooper::factory()->create();
    TrooperGameIdentity::factory()->create(['trooper_id' => $a->id, 'platform_id' => '1']);

    // The pre-check misses the row, as it would when the other claim commits just after it.
    $repo = new class extends TrooperGameIdentityEloquent
    {
        private bool $checked = false;

        public function findByPlatformId(Game $game, string $platformId): ?TrooperGameIdentity
        {
            if (! $this->checked) {
                $this->checked = true;

                return null;
            }

            return parent::findByPlatformId($game, $platformId);
        }
    };

    try {
        $repo->link($b->id, game('hll'), 'Thief', '1', 'Steam ID');
        $this->fail('expected GameIdentityLinkException');
    } catch (GameIdentityLinkException $e) {
        expect($e->getPrevious())->toBeInstanceOf(UniqueConstraintViolationException::class);
    }

    expect(TrooperGameIdentity::query()->sole()->trooper_id)->toBe($a->id);
});

it('replacing a link leaves attendance rows alone', function () {
    $trooper = Trooper::factory()->create();
    $attendance = Attendance::factory()->create(['trooper_id' => $trooper->id]);
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link($trooper->id, game('hll'), 'Maverick', '1', 'Steam ID');
    $repo->link($trooper->id, game('hll'), 'Maverick2', '2', 'Steam ID');

    expect(Attendance::query()->count())->toBe(1)
        ->and($attendance->fresh()->only(['status', 'source', 'rollcall']))->toBe($attendance->only(['status', 'source', 'rollcall']));
});

it('reads the identities of several troopers, with their games, in two queries, none for an empty list (7.2)', function () {
    $a = TrooperGameIdentity::factory()->create(['game_id' => game('hll')->id]);
    TrooperGameIdentity::factory()->create(['trooper_id' => $a->trooper_id, 'game_id' => game('arma')->id]);
    $b = TrooperGameIdentity::factory()->create();
    TrooperGameIdentity::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);

    DB::enableQueryLog();
    $found = $repo->forTroopers([$a->trooper_id, $b->trooper_id]);

    expect($found)->toHaveCount(3)->and(DB::getQueryLog())->toHaveCount(2)
        ->and($repo->forTroopers([]))->toHaveCount(0);
});
