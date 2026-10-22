<?php

use App\Models\GamePlatform;
use App\Models\TrooperGameIdentity;

// Story 7.10's enum widening: rolling it back narrows `platform` to hll/arma again, which the rows
// already linked to WARDOGS cannot survive (MySQL in strict mode fails the ALTER outright).

function wardogsMigration(): object
{
    return require database_path('migrations/2026_10_12_110000_add_wardogs_to_trooper_game_identities_platform.php');
}

it('refuses to roll back while a WARDOGS identity exists, and leaves it linked', function () {
    $wardogs = TrooperGameIdentity::factory()->create(['platform' => GamePlatform::Wardogs]);
    TrooperGameIdentity::factory()->create(['platform' => GamePlatform::Hll]);

    expect(fn () => wardogsMigration()->down())->toThrow(RuntimeException::class, '1 trooper_game_identities row(s) still use it');

    expect($wardogs->fresh()->platform)->toBe(GamePlatform::Wardogs)
        ->and(TrooperGameIdentity::count())->toBe(2);
});

it('rolls back and reapplies when no WARDOGS identity exists', function () {
    $hll = TrooperGameIdentity::factory()->create(['platform' => GamePlatform::Hll]);

    wardogsMigration()->down();
    wardogsMigration()->up();

    expect($hll->fresh()->platform)->toBe(GamePlatform::Hll)
        ->and(TrooperGameIdentity::factory()->create(['platform' => GamePlatform::Wardogs])->fresh()->platform)->toBe(GamePlatform::Wardogs);
});
