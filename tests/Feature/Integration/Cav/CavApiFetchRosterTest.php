<?php

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavFailureReason;
use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\RosterType;
use Carbon\CarbonImmutable;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Story 2.1 — `CavApi::fetchRoster()`, the full-roster read seam. Every Cav call is faked at the
 * AD-3 seam per {@see CavApiTest}'s convention: mocks are keyed by URL, because the sweep issues
 * the same request class three times with different roster types.
 */
const FR_COMBAT_URL = 'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite';
const FR_RESERVE_URL = 'api.7cav.us/api/v1/roster/ROSTER_TYPE_RESERVE/lite';
const FR_ELOA_URL = 'api.7cav.us/api/v1/roster/ROSTER_TYPE_ELOA/lite';

function frEmptyRoster(): MockResponse
{
    return MockResponse::make(['profiles' => []]);
}

/** A `LiteProfile` roster entry as api.7cav.us really sends it (verified live). */
function frRosterRow(string $roster = 'ROSTER_TYPE_COMBAT', array $overrides = []): array
{
    return [...[
        'user' => ['userId' => '3', 'username' => 'Jarvis.A'],
        'rank' => ['rankShort' => 'MG', 'rankFull' => 'Major General', 'rankImageUrl' => '', 'rankId' => '4'],
        'realName' => 'Adam Jarvis',
        'uniformUrl' => '',
        'roster' => $roster,
        'primary' => ['positionTitle' => 'Regimental Technical Aide', 'positionId' => '773'],
        'secondaries' => [],
        'joinDate' => '2012-05-18',
        'promotionDate' => '2025-05-16',
        'discordId' => '',
        'lastForumPostDate' => '',
        'mos' => '255N',
        'consoleGamertag' => '',
    ], ...$overrides];
}

function fetchRosterCav(): CavApi
{
    return new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']);
}

/** Find a trooper in the result's plain array by cav id, for assertion convenience. */
function frFind(array $troopers, int $cavId): ?object
{
    foreach ($troopers as $trooper) {
        if ($trooper->cavId === $cavId) {
            return $trooper;
        }
    }

    return null;
}

it('sweeps every serving roster and merges them into one collection', function () {
    MockClient::destroyGlobal();
    MockClient::global([
        FR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => frRosterRow('ROSTER_TYPE_COMBAT')]]),
        FR_RESERVE_URL => MockResponse::make(['profiles' => ['2' => frRosterRow('ROSTER_TYPE_RESERVE')]]),
        FR_ELOA_URL => MockResponse::make(['profiles' => ['3' => frRosterRow('ROSTER_TYPE_ELOA')]]),
    ]);

    $result = fetchRosterCav()->fetchRoster();
    $cavIds = array_map(fn ($t) => $t->cavId, $result->troopers);
    sort($cavIds);

    expect($result->troopers)->toHaveCount(3)
        ->and($cavIds)->toBe([1, 2, 3])
        ->and(frFind($result->troopers, 1)->status)->toBe(RosterType::Combat)
        ->and(frFind($result->troopers, 2)->status)->toBe(RosterType::Reserve)
        ->and(frFind($result->troopers, 3)->status)->toBe(RosterType::Eloa)
        ->and($result->errors)->toBeEmpty();
});

it('does not walk past the serving rosters into the memorial/past-member ones', function () {
    // No mocks registered for Wall of Honor / Arlington / Past Members — a request to them
    // would throw "no fake response" and fail the test, proving the sweep never reaches them.
    MockClient::destroyGlobal();
    MockClient::global([
        FR_COMBAT_URL => frEmptyRoster(),
        FR_RESERVE_URL => frEmptyRoster(),
        FR_ELOA_URL => frEmptyRoster(),
    ]);

    $result = fetchRosterCav()->fetchRoster();

    expect($result->troopers)->toBeEmpty()->and($result->errors)->toBeEmpty();
});

it('stamps every trooper with the time of this fetch', function () {
    MockClient::destroyGlobal();
    MockClient::global([
        FR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => frRosterRow()]]),
        FR_RESERVE_URL => frEmptyRoster(),
        FR_ELOA_URL => frEmptyRoster(),
    ]);

    CarbonImmutable::setTestNow('2026-08-11 12:00:00');
    $result = fetchRosterCav()->fetchRoster();
    CarbonImmutable::setTestNow();

    expect($result->troopers[0]->fetchedAt)->toEqual(new CarbonImmutable('2026-08-11 12:00:00'));
});

it('collects a malformed row instead of aborting the whole roster', function () {
    MockClient::destroyGlobal();
    MockClient::global([
        FR_COMBAT_URL => MockResponse::make(['profiles' => [
            '1' => ['code' => 5, 'message' => 'this row has no user field at all'],
            '2' => frRosterRow(),
        ]]),
        FR_RESERVE_URL => frEmptyRoster(),
        FR_ELOA_URL => frEmptyRoster(),
    ]);

    $result = fetchRosterCav()->fetchRoster();

    expect($result->troopers)->toHaveCount(1)
        ->and($result->troopers[0]->cavId)->toBe(2)
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->key)->toBe(1);
});

it('collects a row that is not an object rather than aborting', function () {
    MockClient::destroyGlobal();
    MockClient::global([
        FR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => 'not-an-object', '2' => frRosterRow()]]),
        FR_RESERVE_URL => frEmptyRoster(),
        FR_ELOA_URL => frEmptyRoster(),
    ]);

    $result = fetchRosterCav()->fetchRoster();

    expect(array_map(fn ($t) => $t->cavId, $result->troopers))->toBe([2])
        ->and($result->errors)->toHaveCount(1);
});

it('collects a roster key that is not a positive integer instead of aborting', function (mixed $key) {
    MockClient::destroyGlobal();
    MockClient::global([
        FR_COMBAT_URL => MockResponse::make(['profiles' => [(string) $key => frRosterRow(), '2' => frRosterRow()]]),
        FR_RESERVE_URL => frEmptyRoster(),
        FR_ELOA_URL => frEmptyRoster(),
    ]);

    $result = fetchRosterCav()->fetchRoster();

    // The fractional/exponent/non-digit keys below all cast to strings PHP does NOT coerce to
    // an int array key, so they survive as the literal string key this asserts on. A key of
    // '0' or '-5' is the one case PHP *does* coerce to an int key, which is why the id itself is
    // asserted instead — this is exactly the truncation risk the strict parse guards against.
    expect(array_map(fn ($t) => $t->cavId, $result->troopers))->toBe([2])
        ->and($result->errors)->toHaveCount(1);
})->with([
    'non-digit string' => ['abc'],
    'fractional' => ['4.9'],
    'exponent notation' => ['1e3'],
    'zero' => ['0'],
    'negative' => ['-5'],
]);

it('raises a typed error when a roster response carries no profiles map', function () {
    MockClient::destroyGlobal();
    MockClient::global([
        FR_COMBAT_URL => MockResponse::make(['code' => 13, 'message' => 'internal']),
        FR_RESERVE_URL => frEmptyRoster(),
        FR_ELOA_URL => frEmptyRoster(),
    ]);

    try {
        fetchRosterCav()->fetchRoster();
        test()->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::Malformed);
    }
});

it('raises a typed error and returns no partial roster when a roster call fails', function () {
    MockClient::destroyGlobal();
    MockClient::global([
        FR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => frRosterRow()]]),
        FR_RESERVE_URL => MockResponse::make(['code' => 13, 'message' => 'internal'], 500),
        FR_ELOA_URL => frEmptyRoster(),
    ]);

    try {
        fetchRosterCav()->fetchRoster();
        test()->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::HttpError)
            ->and($e->status)->toBe(500);
    }
});

it('surfaces a transport failure as a typed error, not a raw connection error', function () {
    $cav = new CavApi(['base_url' => 'http://127.0.0.1:1', 'api_key' => 'cav7_test-key']);

    try {
        $cav->fetchRoster();
        test()->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::Unreachable);
    }
});

it('rejects a profiles LIST, whose indices would masquerade as milpac ids', function () {
    MockClient::destroyGlobal();
    MockClient::global([
        FR_COMBAT_URL => MockResponse::make(['profiles' => [frRosterRow(), frRosterRow()]]),
        FR_RESERVE_URL => frEmptyRoster(),
        FR_ELOA_URL => frEmptyRoster(),
    ]);

    fetchRosterCav()->fetchRoster();
})->throws(CavRequestException::class);
