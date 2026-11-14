<?php

use App\Models\Trooper;

// "View on 7cav.us": a real trooper's own milpac; a practice trooper's, made-up id and all, a maintainer's real one.

it('links a real trooper to their own milpac', function () {
    expect((new Trooper(['cav_id' => 4012, 'username' => 'Someone.X']))->milpacUrl())
        ->toBe('https://7cav.us/rosters/profile/4012/');
});

it('builds the profile address 7cav.us serves, id then a closing slash, never the old uniqueid query', function () {
    expect(Trooper::milpacUrlFor(3099))->toBe('https://7cav.us/rosters/profile/3099/')
        ->and(Trooper::milpacUrlFor(3099))->not->toContain('uniqueid');
});

it('links the practice maintainers to their own milpac, and every other practice trooper to one of theirs', function () {
    $practice = fn (int $cavId, string $username) => (new Trooper(['cav_id' => $cavId, 'username' => $username]))->milpacUrl();

    expect($practice(91_000_005, 'Laui.M'))->toBe('https://7cav.us/rosters/profile/3099/')
        ->and($practice(91_000_006, 'Grayson.J'))->toBe('https://7cav.us/rosters/profile/4374/')
        ->and([$practice(91_000_001, 'Demo.K'), $practice(91_000_002, 'Sample.R')])
        ->toEqualCanonicalizing(['https://7cav.us/rosters/profile/3099/', 'https://7cav.us/rosters/profile/4374/'])
        ->and($practice(91_900_001, 'Practice.L'))->toStartWith('https://7cav.us/rosters/profile/')
        ->and($practice(91_900_001, 'Practice.L'))->not->toContain('91900001');
});
