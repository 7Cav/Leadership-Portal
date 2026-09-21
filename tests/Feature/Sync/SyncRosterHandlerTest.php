<?php

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavRequestException;
use App\Models\AuditLogEntry;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitType;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitBilletMappingRepository;
use App\Sync\SyncRosterHandler;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Story 2.2 — the sole writer of the `troopers` cache. Every Cav call is faked at the AD-3 seam,
 * matching the full-roster fetch test's convention: mocks are keyed by URL because a full-roster
 * sync issues the same request three times.
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
    return new SyncRosterHandler(
        syncRosterCavApi(),
        app(TrooperRepository::class),
        app(SyncRunRepository::class),
        app(UnitBilletMappingRepository::class),
    );
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

it('does not mark a trooper departed just because their row failed to parse', function () {
    // No `user` key: CavRosterEntry::fromPayload treats this as an error envelope and the row
    // is collected in RosterFetchResult::$errors rather than turned into a CavRosterEntry — but
    // cav id 5 is still the roster's own key, so the trooper is known to be present.
    $stillPresent = Trooper::factory()->create(['cav_id' => 5, 'rank' => 'Sergeant', 'status' => TrooperStatus::Combat]);

    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => ['5' => ['code' => 1, 'message' => 'unavailable']]]),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    expect(Trooper::count())->toBe(1)
        ->and($stillPresent->refresh()->status)->toBe(TrooperStatus::Combat)
        ->and($stillPresent->rank)->toBe('Sergeant');
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

it('records a successful sync run with the row counts, defaulting triggered_by to system', function () {
    Trooper::factory()->create(['cav_id' => 1, 'status' => TrooperStatus::Combat]);
    Trooper::factory()->create(['cav_id' => 99, 'status' => TrooperStatus::Combat]);

    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => [
            '1' => srRosterRow('ROSTER_TYPE_COMBAT'),
            '2' => srRosterRow('ROSTER_TYPE_COMBAT'),
        ]]),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    $run = SyncRun::sole();
    expect($run->source)->toBe('roster')
        ->and($run->status)->toBe(SyncRunStatus::Success)
        ->and($run->inserted)->toBe(1)
        ->and($run->updated)->toBe(1)
        ->and($run->inactivated)->toBe(1)
        ->and($run->errored)->toBe(0)
        ->and($run->error)->toBeNull()
        ->and($run->triggered_by)->toBe('system')
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

it('records a manual sync run under the triggering user id', function () {
    MockClient::global([
        SR_COMBAT_URL => srEmptyRoster(),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle(triggeredBy: 42);

    expect(SyncRun::sole()->triggered_by)->toBe('42');
});

it('records a partial sync run when a row failed to parse, capturing the errored count and detail', function () {
    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => ['5' => ['code' => 1, 'message' => 'unavailable']]]),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    $run = SyncRun::sole();
    expect($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->errored)->toBe(1)
        ->and($run->error)->toContain('5');
});

it('accumulates the errored count and detail across multiple bad rows', function () {
    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => [
            '5' => ['code' => 1, 'message' => 'unavailable'],
            '6' => ['code' => 1, 'message' => 'unavailable'],
        ]]),
        SR_RESERVE_URL => MockResponse::make(['profiles' => ['7' => ['code' => 1, 'message' => 'unavailable']]]),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    $run = SyncRun::sole();
    expect($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->errored)->toBe(3)
        ->and($run->error)->toContain('5')
        ->and($run->error)->toContain('6')
        ->and($run->error)->toContain('7');
});

it('records a failed sync run capturing the error when the Cav seam errors', function () {
    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(status: 500),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    expect(fn () => syncRosterHandler()->handle())->toThrow(CavRequestException::class);

    $run = SyncRun::sole();
    expect($run->status)->toBe(SyncRunStatus::Failure)
        ->and($run->error)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->inserted)->toBe(0)
        ->and($run->updated)->toBe(0)
        ->and($run->inactivated)->toBe(0);
});

it('does not write an audit_log row for a sync run', function () {
    MockClient::global([
        SR_COMBAT_URL => srEmptyRoster(),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    expect(AuditLogEntry::count())->toBe(0);
});

// 2.5 — mapping a trooper's billet (positionId) to a section unit via the curated
// unit_billet_mappings table, on the same sync run that upserts them.

it("resolves a trooper's primary_section_id from their billet mapping", function () {
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    UnitBilletMapping::factory()->create(['unit_id' => $section->id, 'cav_position_id' => 773]);

    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => srRosterRow('ROSTER_TYPE_COMBAT')]]),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    expect(Trooper::where('cav_id', 1)->sole()->primary_section_id)->toBe($section->id);
});

it('leaves primary_section_id null when the billet has no mapping', function () {
    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => srRosterRow('ROSTER_TYPE_COMBAT')]]),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    expect(Trooper::where('cav_id', 1)->sole()->primary_section_id)->toBeNull();
});

it('re-resolves a trooper into a new section automatically when the mapping changes (reorg)', function () {
    $oldSection = Unit::factory()->create(['type' => UnitType::Section]);
    $mapping = UnitBilletMapping::factory()->create(['unit_id' => $oldSection->id, 'cav_position_id' => 773]);

    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => srRosterRow('ROSTER_TYPE_COMBAT')]]),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);
    syncRosterHandler()->handle();
    expect(Trooper::where('cav_id', 1)->sole()->primary_section_id)->toBe($oldSection->id);

    $newSection = Unit::factory()->create(['type' => UnitType::Section]);
    $mapping->update(['unit_id' => $newSection->id]);

    syncRosterHandler()->handle();

    expect(Trooper::where('cav_id', 1)->sole()->primary_section_id)->toBe($newSection->id);
});

it('leaves an existing primary_section_id untouched when the billet no longer resolves', function () {
    // Forward-looking to 2.7's manual placement: the sync must never re-null a trooper it simply
    // can't resolve anymore — only ever overwrite when it *does* resolve.
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    Trooper::factory()->create(['cav_id' => 1, 'primary_section_id' => $section->id, 'status' => TrooperStatus::Combat]);

    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => srRosterRow('ROSTER_TYPE_COMBAT')]]),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    expect(Trooper::where('cav_id', 1)->sole()->primary_section_id)->toBe($section->id);
});

it('does not resolve a mapping that points at a non-section unit', function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);
    UnitBilletMapping::factory()->create(['unit_id' => $platoon->id, 'cav_position_id' => 773]);

    MockClient::global([
        SR_COMBAT_URL => MockResponse::make(['profiles' => ['1' => srRosterRow('ROSTER_TYPE_COMBAT')]]),
        SR_RESERVE_URL => srEmptyRoster(),
        SR_ELOA_URL => srEmptyRoster(),
    ]);

    syncRosterHandler()->handle();

    expect(Trooper::where('cav_id', 1)->sole()->primary_section_id)->toBeNull();
});
