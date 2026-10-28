<?php

use App\Models\Trooper;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\TrooperGameIdentityRepository;

// #428: the kind an identity was entered as, stored beside the id.

it('stores the kind an identity was entered as, and lists the kinds a game\'s identities use', function () {
    $hll = game('hll');
    $hll->update(['id_kinds' => ['Steam ID', 'Epic ID', 'Xbox ID']]);
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link(Trooper::factory()->create()->id, $hll, 'A', '1', 'Epic ID');
    $repo->link(Trooper::factory()->create()->id, $hll, 'B', '2', 'Epic ID');
    $repo->link(Trooper::factory()->create()->id, game('arma'), 'C', '3', 'Player ID');

    expect($repo->kindsInUse($hll))->toBe(['Epic ID'])
        ->and($repo->kindsInUse(game('arma')))->toBe(['Player ID'])
        ->and($repo->kindsInUse(game('wardogs')))->toBe([]);
});

it('refuses the same id on a game under another kind', function () {
    $hll = game('hll');
    $hll->update(['id_kinds' => ['Steam ID', 'Epic ID']]);
    $repo = app(TrooperGameIdentityRepository::class);
    $repo->link(Trooper::factory()->create()->id, $hll, 'A', '0002105c', 'Steam ID');

    expect(fn () => $repo->link(Trooper::factory()->create()->id, $hll, 'B', '0002105c', 'Epic ID'))
        ->toThrow(GameIdentityLinkException::class);
});
