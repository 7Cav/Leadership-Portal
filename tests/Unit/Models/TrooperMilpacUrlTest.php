<?php

use App\Models\Trooper;

// "View on 7cav.us": a real trooper's own milpac; a practice trooper's, made-up id and all, a maintainer's real one.

it('links a real trooper to their own milpac', function () {
    expect((new Trooper(['cav_id' => 4012, 'username' => 'Someone.X']))->milpacUrl())
        ->toBe('https://7cav.us/rosters/profile?uniqueid=4012');
});

it('links the practice maintainers to their own milpac, and every other practice trooper to one of theirs', function () {
    $practice = fn (int $cavId, string $username) => (new Trooper(['cav_id' => $cavId, 'username' => $username]))->milpacUrl();

    expect($practice(91_000_005, 'Laui.M'))->toBe(Trooper::MILPAC_PROFILE_URL.'3099')
        ->and($practice(91_000_006, 'Grayson.J'))->toBe(Trooper::MILPAC_PROFILE_URL.'4374')
        ->and([$practice(91_000_001, 'Demo.K'), $practice(91_000_002, 'Sample.R')])
        ->toEqualCanonicalizing([Trooper::MILPAC_PROFILE_URL.'3099', Trooper::MILPAC_PROFILE_URL.'4374'])
        ->and($practice(91_900_001, 'Practice.L'))->toStartWith(Trooper::MILPAC_PROFILE_URL)
        ->and($practice(91_900_001, 'Practice.L'))->not->toContain('91900001');
});
