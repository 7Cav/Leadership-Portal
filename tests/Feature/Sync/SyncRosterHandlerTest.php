<?php

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavRequestException;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Repositories\TrooperRepository;
use App\Sync\SyncRosterHandler;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Feature\Integration\Cav\CavApiFetchRosterTest;

/**
 * Story 2.2 — the sole writer of the `troopers` cache. Every Cav call is faked at the AD-3 seam,
 * matching {@see CavApiFetchRosterTest}'s convention: mocks are
 * keyed by URL because a full-roster sync issues the same request three times.
 */
const SR_COMBAT_URL = 'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite';
const SR_RESERVE_URL = 'api.7cav.us/api/v1/roster/ROSTER_TYPE_RESERVE/lite';
const SR_ELOA_URL = 'api.7cav.us/api/v1/roster/ROSTER_TYPE_ELOA/lite';

function srEmptyRoster(): MockResponse
{
    return MockResponse::make(['profiles' => []]);
}

/** A `LiteProfile` roster entry, as api.7cav.us really sends it. */
function srRosterRow(string $roster = 'ROSTER_TYPE_COMBAT', array $overrides = []): array
{
    return [...[
        'user' => ['userId' => '3', 'username' => 'Jarvis.A'],
        'rank' => ['rankShort' => 'SGT', 'rankFull' => 'Sergeant', 'rankImageUrl' => '', 'rankId' => '4'],
        'realName' => 'Adam Jarvis',
        'uniformUrl' => '',
        'roster' => $roster,
        'primary' => ['positionTitle' => 'Rifleman', 'positionId' => '773'],
        'secondaries' => [],
        'joinDate' => '2020-01-01',
        'promotionDate' => '2022-01-01',
        'discordId' => '',
        'lastForumPostDate' => '',
        'mos' => '11B',
        'consoleGamertag' => '',
    ], ...$overrides];
}

function syncRosterCavApi(): CavApi
{
    return new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']);
}

function syncRosterHandler(): SyncRosterHandler
{
    return new SyncRosterHandler(syncRosterCavApi(), app(TrooperRepository::class));
}

beforeEach(function () {
    MockClient::destroyGlobal();
});

it('inserts every trooper on the merged roster, mapping their roster status', function () {
    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => srRosterRow('ROSTER_TYPE_COMBAT')]]),
        SR_RESERVE_URL => MockResponse::make(['profiles' => ['2' => srRosterRow('ROSTER_TYPE_RESERVE')]]),
        SR_ELOA_URL => MockResponse::make(['profiles' => ['3' => srRosterRow('ROSTER_TYPE_ELOA')]]),
    ]);

    syncRosterHandler()->handle();

    expect(Trooper::count())->toBe(3)
        ->and(Trooper::where('cav_id', 1)->sole()->status)->toBe(TrooperStatus::Combat)
        ->and(Trooper::where('cav_id', 2)->sole()->status)->toBe(TrooperStatus::Reserve)
        ->and(Trooper::where('cav_id', 3)->sole()->status)->toBe(TrooperStatus::Eloa);
});

it('updates an existing trooper in place rather than duplicating the row', function () {
    $existing = Trooper::factory()->create(['cav_id' => 1, 'rank' => 'Private', 'status' => TrooperStatus::Combat]);

    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => srRosterRow('ROSTER_TYPE_COMBAT', ['rank' => ['rankShort' => 'SGT', 'rankFull' => 'Sergeant', 'rankImageUrl' => '', 'rankId' => '4']])]]),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    expect(Trooper::count())->toBe(1);
    $existing->refresh();
    expect($existing->rank)->toBe('Sergeant');
});

it('marks a trooper missing from every roster as inactive without deleting the row', function () {
    $departed = Trooper::factory()->create(['cav_id' => 99, 'status' => TrooperStatus::Combat]);

    MockClient::global([
        SR_COMBAT_URL => srEmptyRoster(),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    expect(Trooper::count())->toBe(1)
        ->and($departed->refresh()->status)->toBe(TrooperStatus::Inactive);
});

it('reactivates a previously-departed trooper who reappears on the roster', function () {
    $trooper = Trooper::factory()->create(['cav_id' => 1, 'status' => TrooperStatus::Inactive]);

    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => srRosterRow('ROSTER_TYPE_COMBAT')]]),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    expect(Trooper::count())->toBe(1)
        ->and($trooper->refresh()->status)->toBe(TrooperStatus::Combat);
});

it('aborts before writing anything when the Cav seam fails, leaving the last good cache intact', function () {
    $existing = Trooper::factory()->create(['cav_id' => 1, 'rank' => 'Sergeant', 'status' => TrooperStatus::Combat]);

    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(status: 500),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    expect(fn () => syncRosterHandler()->handle())->toThrow(CavRequestException::class);

    expect(Trooper::count())->toBe(1);
    expect($existing->refresh()->rank)->toBe('Sergeant')
        ->and($existing->status)->toBe(TrooperStatus::Combat);
});
