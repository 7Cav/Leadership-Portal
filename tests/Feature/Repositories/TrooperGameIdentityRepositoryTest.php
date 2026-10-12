<?php

use App\Models\Attendance;
use App\Models\GamePlatform;
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
        'platform' => 'hll',
        'player_name' => 'Maverick',
        'platform_id' => '76561198000000001',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('creates the table with the identity columns', function () {
    expect(Schema::hasColumns('trooper_game_identities', [
        'id', 'trooper_id', 'platform', 'player_name', 'platform_id', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('refuses a second row for the same (platform, platform_id) at the database', function () {
    DB::table('trooper_game_identities')->insert(tgiRaw());

    expect(fn () => DB::table('trooper_game_identities')->insert(tgiRaw()))->toThrow(QueryException::class);
});

it('allows the same platform_id on a different platform', function () {
    DB::table('trooper_game_identities')->insert(tgiRaw());
    DB::table('trooper_game_identities')->insert(tgiRaw(['platform' => 'arma']));

    expect(DB::table('trooper_game_identities')->count())->toBe(2);
});

it('accepts a wardogs identity alongside the same steam id linked for hll', function () {
    DB::table('trooper_game_identities')->insert(tgiRaw());
    DB::table('trooper_game_identities')->insert(tgiRaw(['platform' => 'wardogs']));

    expect(DB::table('trooper_game_identities')->count())->toBe(2)
        ->and(GamePlatform::Wardogs->value)->toBe('wardogs');
});

it('refuses a second identity for one trooper on one platform at the database', function () {
    $trooper = Trooper::factory()->create();
    DB::table('trooper_game_identities')->insert(tgiRaw(['trooper_id' => $trooper->id]));

    expect(fn () => DB::table('trooper_game_identities')->insert(
        tgiRaw(['trooper_id' => $trooper->id, 'platform_id' => '76561198000000002'])
    ))->toThrow(QueryException::class);
});

it('refuses a platform outside hll and arma', function () {
    expect(fn () => DB::table('trooper_game_identities')->insert(tgiRaw(['platform' => 'squad'])))
        ->toThrow(QueryException::class);
});

it('links a trooper and finds the link by platform id', function () {
    $trooper = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);

    $link = $repo->link($trooper->id, GamePlatform::Hll, 'Maverick', '76561198000000001');

    expect($link->trooper_id)->toBe($trooper->id)
        ->and($link->platform)->toBe(GamePlatform::Hll)
        ->and($repo->findByPlatformId(GamePlatform::Hll, '76561198000000001')?->is($link))->toBeTrue()
        ->and($repo->findByPlatformId(GamePlatform::Arma, '76561198000000001'))->toBeNull();
});

it('lists a trooper identities, one per platform', function () {
    $trooper = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link($trooper->id, GamePlatform::Hll, 'Maverick', '1');
    $repo->link($trooper->id, GamePlatform::Arma, 'Mav', '2');
    $repo->link(Trooper::factory()->create()->id, GamePlatform::Hll, 'Other', '3');

    expect($repo->forTrooper($trooper->id)->pluck('platform')->all())
        ->toBe([GamePlatform::Hll, GamePlatform::Arma]);
});

it('replaces the trooper own identity on a platform instead of adding a duplicate', function () {
    $trooper = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);
    $first = $repo->link($trooper->id, GamePlatform::Hll, 'Maverick', '1');

    $second = $repo->link($trooper->id, GamePlatform::Hll, 'Maverick2', '2');

    expect(TrooperGameIdentity::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->platform_id)->toBe('2')
        ->and($second->player_name)->toBe('Maverick2');
});

it('is idempotent when the same trooper re-links the same player', function () {
    $trooper = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link($trooper->id, GamePlatform::Hll, 'Maverick', '1');
    $repo->link($trooper->id, GamePlatform::Hll, 'Renamed', '1');

    expect(TrooperGameIdentity::query()->sole()->player_name)->toBe('Renamed');
});

it('rejects linking a platform id already held by another trooper and leaves the link intact', function () {
    $a = Trooper::factory()->create();
    $b = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link($a->id, GamePlatform::Hll, 'Maverick', '1');

    expect(fn () => $repo->link($b->id, GamePlatform::Hll, 'Thief', '1'))
        ->toThrow(GameIdentityLinkException::class);

    $row = TrooperGameIdentity::query()->sole();
    expect($row->trooper_id)->toBe($a->id)->and($row->player_name)->toBe('Maverick');
});

it('does not replace trooper B existing identity when the claim on A fails', function () {
    $a = Trooper::factory()->create();
    $b = Trooper::factory()->create();
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link($a->id, GamePlatform::Hll, 'Maverick', '1');
    $repo->link($b->id, GamePlatform::Hll, 'Goose', '2');

    expect(fn () => $repo->link($b->id, GamePlatform::Hll, 'Goose', '1'))
        ->toThrow(GameIdentityLinkException::class);

    expect($repo->forTrooper($b->id)->sole()->platform_id)->toBe('2');
});

it('unlinks without touching attendance rows', function () {
    $trooper = Trooper::factory()->create();
    $attendance = Attendance::factory()->create(['trooper_id' => $trooper->id]);
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link($trooper->id, GamePlatform::Hll, 'Maverick', '1');

    expect($repo->unlink($trooper->id, GamePlatform::Hll))->toBeTrue()
        ->and($repo->unlink($trooper->id, GamePlatform::Hll))->toBeFalse()
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

        public function findByPlatformId(GamePlatform $platform, string $platformId): ?TrooperGameIdentity
        {
            if (! $this->checked) {
                $this->checked = true;

                return null;
            }

            return parent::findByPlatformId($platform, $platformId);
        }
    };

    try {
        $repo->link($b->id, GamePlatform::Hll, 'Thief', '1');
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
    $repo->link($trooper->id, GamePlatform::Hll, 'Maverick', '1');
    $repo->link($trooper->id, GamePlatform::Hll, 'Maverick2', '2');

    expect(Attendance::query()->count())->toBe(1)
        ->and($attendance->fresh()->only(['status', 'source', 'rollcall']))->toBe($attendance->only(['status', 'source', 'rollcall']));
});
