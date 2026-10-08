<?php

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavFailureReason;
use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\CavPosition;
use App\Integration\Cav\Data\CavPositionGroup;
use Carbon\CarbonImmutable;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Story 2.9 — `CavApi::fetchPositionGroups()`, the read seam the org-structure import builds on.
 * Faked at the AD-3 seam by URL, matching the other Cav seam tests.
 */
const PG_URL = 'api.7cav.us/api/v1/milpacs/position/groups';

function pgCav(): CavApi
{
    return new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']);
}

/** The trimmed live payload under tests/Fixtures/cav — real titles and ids, a subset of groups. */
function pgFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/cav/position-groups.json')), true, flags: JSON_THROW_ON_ERROR);
}

function pgExpectMalformed(array|string $body, string $messagePart): void
{
    MockClient::global([PG_URL => MockResponse::make($body)]);

    try {
        pgCav()->fetchPositionGroups();
        test()->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::Malformed)
            ->and($e->getMessage())->toContain($messagePart);
    }
}

it('returns typed, immutable group and position DTOs from the live payload shape', function () {
    CarbonImmutable::setTestNow('2026-10-08 12:00:00');
    MockClient::global([PG_URL => MockResponse::make(pgFixture())]);

    $result = pgCav()->fetchPositionGroups();
    $alpha = collect($result->groups)->firstWhere('title', 'A/1-7');

    expect($result->groups)->each->toBeInstanceOf(CavPositionGroup::class)
        ->and($result->fetchedAt->format('Y-m-d H:i:s'))->toBe('2026-10-08 12:00:00')
        ->and($alpha->id)->toBe(5)
        ->and($alpha->displayOrder)->toBe(40)
        ->and($alpha->positions)->each->toBeInstanceOf(CavPosition::class)
        ->and($alpha->positions[0]->id)->toBe(196)
        ->and($alpha->positions[0]->title)->toBe('Commander A/1-7');

    // readonly all the way down — a caller cannot rewrite what Cav said.
    expect(fn () => $alpha->positions[0]->title = 'x')->toThrow(Error::class);

    CarbonImmutable::setTestNow();
});

it('sorts groups and positions into Cav display order, whatever order the payload uses', function () {
    MockClient::global([PG_URL => MockResponse::make(['groups' => [
        ['groupId' => '2', 'groupTitle' => 'B/1-7', 'groupDisplayOrder' => 50, 'positions' => [
            ['positionTitle' => 'Trooper 1/1/B/1-7', 'positionId' => '12', 'positionDisplayOrder' => 30],
            ['positionTitle' => 'Commander B/1-7', 'positionId' => '10', 'positionDisplayOrder' => 10],
        ]],
        ['groupId' => '1', 'groupTitle' => 'A/1-7', 'groupDisplayOrder' => 40, 'positions' => []],
    ]])]);

    $groups = pgCav()->fetchPositionGroups()->groups;

    expect(array_map(fn ($g) => $g->title, $groups))->toBe(['A/1-7', 'B/1-7'])
        ->and(array_map(fn ($p) => $p->id, $groups[1]->positions))->toBe([10, 12]);
});

it('raises a typed HTTP error rather than returning a partial structure', function () {
    MockClient::global([PG_URL => MockResponse::make(['message' => 'nope'], 503)]);

    try {
        pgCav()->fetchPositionGroups();
        $this->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::HttpError)
            ->and($e->status)->toBe(503);
    }
});

it('refuses a payload with no groups list', function () {
    pgExpectMalformed(['code' => 5, 'message' => 'error envelope'], 'no `groups` list');
});

it('refuses an empty groups list — Cav always publishes its holding groups', function () {
    pgExpectMalformed(['groups' => []], 'no `groups` list');
});

it('refuses a group with no title', function () {
    pgExpectMalformed(['groups' => [['groupId' => '5', 'positions' => []]]], 'group 5 carried no `groupTitle`');
});

it('refuses a group whose positions are not a list', function () {
    pgExpectMalformed(['groups' => [['groupId' => '5', 'groupTitle' => 'A/1-7', 'positions' => 'x']]], 'no `positions` list');
});

it('refuses a position with no usable id rather than guess a mapping key', function () {
    pgExpectMalformed(['groups' => [['groupId' => '5', 'groupTitle' => 'A/1-7', 'positions' => [
        ['positionTitle' => 'Trooper 1/1/A/1-7', 'positionId' => 'abc'],
    ]]]], 'no usable `positionId`');
});

it('refuses a position with no title', function () {
    pgExpectMalformed(['groups' => [['groupId' => '5', 'groupTitle' => 'A/1-7', 'positions' => [
        ['positionId' => '201', 'positionTitle' => ''],
    ]]]], 'position 201 carried no `positionTitle`');
});

it('refuses a non-object position', function () {
    pgExpectMalformed(['groups' => [['groupId' => '5', 'groupTitle' => 'A/1-7', 'positions' => ['nope']]]], 'not an object');
});

it('fails before sending when no key is configured', function () {
    $cav = new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => '']);

    expect(fn () => $cav->fetchPositionGroups())->toThrow(CavRequestException::class, 'CAV_API_KEY');
});

it('names a group with no usable id by its title', function () {
    pgExpectMalformed(['groups' => [['groupTitle' => 'A/1-7', 'positions' => []]]], "group 'A/1-7' carried no usable `groupId`");
});
