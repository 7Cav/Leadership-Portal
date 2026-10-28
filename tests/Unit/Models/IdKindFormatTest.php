<?php

use App\Models\IdKindFormat;

// #450: the one list of ID shapes, by kind name.

it('knows a Steam ID as a SteamID64: 17 digits starting 7656119', function (string $id, bool $ok) {
    expect(IdKindFormat::for('Steam ID')->accepts($id))->toBe($ok);
})->with([
    ['76561198012345678', true],
    ['76561197960265728', true],
    ['7656119801234567', false],
    ['765611980123456789', false],
    ['86561198012345678', false],
    ['7656119801234567a', false],
    ['STEAM_0:1:12345', false],
    ['', false],
]);

it('knows an Epic ID as 32 hex characters, stored lower case as CRCON reports it', function () {
    $epic = IdKindFormat::for('Epic ID');

    expect($epic->canonical('0002105C01B748ADA8D8CF5B32E70093'))->toBe('0002105c01b748ada8d8cf5b32e70093')
        ->and($epic->accepts('0002105c01b748ada8d8cf5b32e70093'))->toBeTrue()
        ->and($epic->accepts('0002105C01B748ADA8D8CF5B32E70093'))->toBeFalse()
        ->and($epic->accepts('0002105c01b748ada8d8cf5b32e7009'))->toBeFalse()
        ->and($epic->accepts('0002105c01b748ada8d8cf5b32e70093a'))->toBeFalse()
        ->and($epic->accepts('0002105c01b748ada8d8cf5b32e7009g'))->toBeFalse();
});

it('matches a kind by name whatever its case and spacing', function () {
    expect(IdKindFormat::for('steam id'))->not->toBeNull()
        ->and(IdKindFormat::for('SteamID'))->not->toBeNull()
        ->and(IdKindFormat::for(' EPIC ID '))->not->toBeNull();
});

it('has no rule for a kind without a certain shape, so those keep the free check', function (string $kind) {
    expect(IdKindFormat::for($kind))->toBeNull();
})->with(['Xbox ID', 'PSN ID', 'Player ID', 'DCS UCID', 'Gamertag', '']);

it('says what the ID should look like', function () {
    expect(IdKindFormat::for('Steam ID')->message())->toBe('This is not a valid Steam ID. A Steam ID is 17 digits starting 7656119, for example 76561198012345678.')
        ->and(IdKindFormat::for('Epic ID')->hint)->toBe('An Epic ID is 32 characters, the digits 0 to 9 and the letters a to f.');
});

it('changes nothing but the case of an Epic ID', function () {
    expect(IdKindFormat::for('Steam ID')->canonical('AbC'))->toBe('AbC');
});
