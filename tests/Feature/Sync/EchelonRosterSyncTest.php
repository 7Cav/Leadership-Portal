<?php

use App\Http\Controllers\UnitRosterSyncController;
use App\Jobs\SyncRosterJob;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SyncRunRepository;
use App\Repositories\UnitRepository;
use App\Sync\EmptyRosterException;
use App\Sync\SyncRosterHandler;
use App\Sync\UnitNoLongerExistsException;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * An echelon leader's manual sync: Cav serves whole rosters, so the fetch is the full one, but only the
 * leader's unit subtree may be written. Cav is faked at the AD-3 seam, keyed by URL.
 */
function echelonRow(string $username, int $positionId, string $rank = 'Sergeant'): array
{
    return [
        'user' => ['userId' => '3', 'username' => $username],
        'rank' => ['rankShort' => 'SGT', 'rankFull' => $rank, 'rankImageUrl' => '', 'rankId' => '4'],
        'realName' => 'Real Name',
        'uniformUrl' => '',
        'roster' => 'ROSTER_TYPE_COMBAT',
        'primary' => ['positionTitle' => 'Rifleman', 'positionId' => (string) $positionId],
        'secondaries' => [],
        'joinDate' => '2020-01-01',
        'promotionDate' => '2022-01-01',
        'discordId' => '',
        'lastForumPostDate' => '',
        'mos' => '11B',
        'consoleGamertag' => '',
    ];
}

/** @return array{platoon: Unit, inside: Unit, outside: Unit} */
function echelonTree(): array
{
    $units = app(UnitRepository::class);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $otherPlatoon = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $company->id);
    $inside = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $outside = $units->create(UnitType::Section, '3rd Section', '1/2/A/1-7', $otherPlatoon->id);

    UnitBilletMapping::factory()->create(['unit_id' => $inside->id, 'cav_position_id' => 101]);
    UnitBilletMapping::factory()->create(['unit_id' => $outside->id, 'cav_position_id' => 202]);

    return compact('platoon', 'inside', 'outside');
}

function echelonFakeRoster(array $combatProfiles): void
{
    MockClient::global([
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => MockResponse::make(['profiles' => $combatProfiles]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_RESERVE/lite' => MockResponse::make(['profiles' => []]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_ELOA/lite' => MockResponse::make(['profiles' => []]),
    ]);
}

beforeEach(function () {
    MockClient::destroyGlobal();
    config(['services.cav.base_url' => 'https://api.7cav.us', 'services.cav.api_key' => 'cav7_test-key']);
});

it('writes only the leader\'s echelon: updates, arrivals and departures stay inside the subtree', function () {
    $tree = echelonTree();

    $mine = Trooper::factory()->create(['cav_id' => 1, 'rank' => 'Private', 'status' => TrooperStatus::Combat, 'primary_section_id' => $tree['inside']->id]);
    $gone = Trooper::factory()->create(['cav_id' => 3, 'status' => TrooperStatus::Combat, 'primary_section_id' => $tree['inside']->id]);
    $theirs = Trooper::factory()->create(['cav_id' => 2, 'rank' => 'Private', 'status' => TrooperStatus::Combat, 'primary_section_id' => $tree['outside']->id]);
    $theirsGone = Trooper::factory()->create(['cav_id' => 4, 'status' => TrooperStatus::Combat, 'primary_section_id' => $tree['outside']->id]);

    echelonFakeRoster([
        '1' => echelonRow('Mine.A', 101),
        '2' => echelonRow('Theirs.B', 202),
        '5' => echelonRow('Arrival.C', 101),
        '6' => echelonRow('Elsewhere.D', 202),
    ]);

    app(SyncRosterHandler::class)->handle(7, $tree['platoon']->id);

    expect($mine->refresh()->rank)->toBe('Sergeant')
        ->and($gone->refresh()->status)->toBe(TrooperStatus::Inactive)
        ->and(Trooper::where('cav_id', 5)->sole()->primary_section_id)->toBe($tree['inside']->id)
        ->and($theirs->refresh()->rank)->toBe('Private')
        ->and($theirsGone->refresh()->status)->toBe(TrooperStatus::Combat)
        ->and(Trooper::where('cav_id', 6)->exists())->toBeFalse();
});

it('records an echelon run under its own source so the roster-wide stamp does not move', function () {
    $tree = echelonTree();
    echelonFakeRoster(['1' => echelonRow('Mine.A', 101)]);

    app(SyncRosterHandler::class)->handle(7, $tree['platoon']->id);

    $runs = app(SyncRunRepository::class);
    expect($runs->latestSuccessful('roster-unit'))->not->toBeNull()
        ->and($runs->latestSuccessful('roster-unit')->triggered_by)->toBe('7')
        ->and($runs->latestSuccessful('roster'))->toBeNull();
});

it('refuses a unit that has since been deleted before opening any run, writing nothing', function () {
    echelonFakeRoster(['1' => echelonRow('Mine.A', 101)]);

    expect(fn () => app(SyncRosterHandler::class)->handle(7, 999999))->toThrow(UnitNoLongerExistsException::class)
        ->and(Trooper::count())->toBe(0)
        ->and(SyncRun::count())->toBe(0);
});

it('fails the job at once for a deleted unit rather than retrying a permanent error', function () {
    $job = Mockery::mock(SyncRosterJob::class.'[fail]', [7, 999999]);
    $job->shouldReceive('fail')->once()->with(Mockery::type(UnitNoLongerExistsException::class));

    $job->handle(app(SyncRosterHandler::class));
});

it('does not refuse an echelon sync because troopers elsewhere moved rosters', function () {
    $tree = echelonTree();
    // Four ELOA troopers in another echelon, all promoted to Combat this run: ELOA comes back empty, which the full
    // sync accepts because every one of them is still active. The echelon sync must judge it the same way.
    foreach ([11, 12, 13, 14] as $cavId) {
        Trooper::factory()->create(['cav_id' => $cavId, 'status' => TrooperStatus::Eloa, 'primary_section_id' => $tree['outside']->id]);
    }
    echelonFakeRoster(collect([11, 12, 13, 14])->mapWithKeys(fn ($id) => [(string) $id => echelonRow("Moved.{$id}", 202)])->all());

    app(SyncRosterHandler::class)->handle(7, $tree['platoon']->id);

    expect(app(SyncRunRepository::class)->latestSuccessful('roster-unit'))->not->toBeNull();
});

it('still refuses an empty roster that would depart a crowd, under an echelon sync too', function () {
    $tree = echelonTree();
    foreach (range(21, 26) as $cavId) {
        Trooper::factory()->create(['cav_id' => $cavId, 'status' => TrooperStatus::Reserve, 'primary_section_id' => $tree['outside']->id]);
    }
    echelonFakeRoster(['1' => echelonRow('Mine.A', 101)]);

    expect(fn () => app(SyncRosterHandler::class)->handle(7, $tree['platoon']->id))->toThrow(EmptyRosterException::class)
        ->and(Trooper::where('status', TrooperStatus::Reserve)->count())->toBe(6)
        ->and(app(SyncRunRepository::class)->latestFinished('roster-unit')->status)->toBe(SyncRunStatus::Failure);
});

it('writes a trooper whose billet moved them out of the echelon through to their new section, and does not depart them', function () {
    $tree = echelonTree();
    $moved = Trooper::factory()->create(['cav_id' => 1, 'status' => TrooperStatus::Combat, 'primary_section_id' => $tree['inside']->id]);
    echelonFakeRoster(['1' => echelonRow('Mover.A', 202)]);

    app(SyncRosterHandler::class)->handle(7, $tree['platoon']->id);

    expect($moved->refresh()->primary_section_id)->toBe($tree['outside']->id)
        ->and($moved->status)->toBe(TrooperStatus::Combat);
});

it('reactivates a departed trooper placed in the echelon who is back on a roster', function () {
    $tree = echelonTree();
    $back = Trooper::factory()->create(['cav_id' => 1, 'status' => TrooperStatus::Inactive, 'primary_section_id' => $tree['inside']->id]);
    echelonFakeRoster(['1' => echelonRow('Back.A', 999)]);

    app(SyncRosterHandler::class)->handle(7, $tree['platoon']->id);

    expect($back->refresh()->status)->toBe(TrooperStatus::Combat);
});

function echelonLeader(Unit $commands): User
{
    Trooper::factory()->create(['cav_id' => 9001, 'cav_position_id' => 900, 'status' => TrooperStatus::Combat]);
    UnitCommandMapping::factory()->create(['unit_id' => $commands->id, 'cav_position_id' => 900]);

    return User::factory()->create(['cav_id' => 9001]);
}

it('lets a leader sync a unit they command, or one beneath it, stamping them as the trigger', function (string $target) {
    Queue::fake();
    $tree = echelonTree();
    $leader = echelonLeader($tree['platoon']);

    $this->actingAs($leader)->post("/units/{$tree[$target]->id}/roster-sync")->assertRedirect();

    Queue::assertPushed(SyncRosterJob::class, fn (SyncRosterJob $job) => $job->unitId === $tree[$target]->id && $job->triggeredBy === $leader->id);
})->with(['the unit they command' => 'platoon', 'a unit beneath it' => 'inside']);

it('refuses a leader a unit outside their command, and a plain user any unit', function () {
    Queue::fake();
    $tree = echelonTree();

    $this->actingAs(echelonLeader($tree['platoon']))->post("/units/{$tree['outside']->id}/roster-sync")->assertForbidden();
    $this->actingAs(User::factory()->create())->post("/units/{$tree['inside']->id}/roster-sync")->assertForbidden();

    Queue::assertNothingPushed();
});

it('refuses a unit that does not exist the same way as one that is not theirs', function () {
    Queue::fake();
    echelonTree();

    $this->actingAs(User::factory()->create())->post('/units/999999/roster-sync')->assertForbidden();

    Queue::assertNothingPushed();
});

it('lets an Admin sync any single echelon', function () {
    Queue::fake();
    $tree = echelonTree();

    $this->actingAs(User::factory()->admin()->create())->post("/units/{$tree['outside']->id}/roster-sync")->assertRedirect();

    Queue::assertPushed(SyncRosterJob::class, fn (SyncRosterJob $job) => $job->unitId === $tree['outside']->id);
});

it('queues one sync per echelon however often it is clicked, apart from the whole-roster one', function () {
    Queue::fake();
    $tree = echelonTree();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->post("/units/{$tree['inside']->id}/roster-sync")->assertRedirect();
    $this->post("/units/{$tree['inside']->id}/roster-sync")->assertStatus(409);
    $this->post("/units/{$tree['outside']->id}/roster-sync")->assertRedirect();
    $this->post('/settings/roster-sync');

    Queue::assertPushed(SyncRosterJob::class, 3);
});

it('tells a leader instead of dropping the click when a whole-roster sync already covers their unit', function () {
    Queue::fake();
    $tree = echelonTree();
    SyncRosterJob::dispatch('system');

    $this->actingAs(echelonLeader($tree['platoon']))->post("/units/{$tree['inside']->id}/roster-sync")->assertStatus(409);

    Queue::assertPushed(SyncRosterJob::class, 1);
});

it('offers the unit sync to the leader of that unit only, following real authority', function () {
    $tree = echelonTree();
    $leader = echelonLeader($tree['platoon']);

    $page = fn (User $user, int $unitId) => $this->actingAs($user)->get("/roster/{$unitId}")->assertOk()->viewData('page')['props']['can'];

    expect($page($leader, $tree['inside']->id)['syncUnit'])->toBeTrue()
        ->and($page(User::factory()->admin()->create(), $tree['inside']->id))->toMatchArray(['sync' => true, 'syncUnit' => true]);
});

it('records which unit an echelon run was for, so that unit\'s leader can see how it went', function () {
    $tree = echelonTree();
    echelonFakeRoster(['1' => echelonRow('Mine.A', 101)]);

    app(SyncRosterHandler::class)->handle(7, $tree['platoon']->id);

    $runs = app(SyncRunRepository::class);
    expect($runs->latestForUnit('roster-unit', $tree['platoon']->id)->status)->toBe(SyncRunStatus::Success)
        ->and($runs->latestForUnit('roster-unit', $tree['outside']->id))->toBeNull();
});

it('closes a run a killed worker left in progress, for either source, before opening the next', function () {
    $tree = echelonTree();
    $runs = app(SyncRunRepository::class);
    $whole = $runs->open('roster', 'system');
    $unit = $runs->open('roster-unit', 7, unitId: $tree['platoon']->id);
    echelonFakeRoster(['1' => echelonRow('Mine.A', 101)]);

    app(SyncRosterHandler::class)->handle(7, $tree['platoon']->id);

    expect($whole->refresh()->status)->toBe(SyncRunStatus::Failure)
        ->and($unit->refresh()->status)->toBe(SyncRunStatus::Failure);
});

it('shows a unit leader how their last sync of the unit went, and shows nobody else', function () {
    $tree = echelonTree();
    $leader = echelonLeader($tree['platoon']);
    $runs = app(SyncRunRepository::class);
    $runs->fail($runs->open('roster-unit', $leader->id, unitId: $tree['inside']->id), 'boom');

    $sync = fn (User $user) => $this->actingAs($user)->get("/roster/{$tree['inside']->id}")->assertOk()->viewData('page')['props']['sync'];

    expect($sync($leader)['unit'])->toMatchArray(['status' => 'failure'])
        ->and($sync(User::factory()->admin()->create())['unit'])->toMatchArray(['status' => 'failure'])
        // Never leaks the raw error text, which can carry internals.
        ->and(json_encode($sync($leader)))->not->toContain('boom');
});

it('caps how many echelon sync requests one leader can make in a minute', function () {
    Queue::fake();
    $tree = echelonTree();
    // A pending whole-roster sync refuses every click with a 409, so only the per-user throttle can answer 429.
    SyncRosterJob::dispatch('system');
    $this->actingAs(echelonLeader($tree['platoon']));

    foreach ([$tree['platoon'], $tree['inside'], $tree['platoon']] as $unit) {
        $this->post("/units/{$unit->id}/roster-sync")->assertStatus(409);
    }

    $this->post("/units/{$tree['inside']->id}/roster-sync")->assertStatus(429);
});

it('accepts one unit sync per cooldown across nested units, so Cav sees a few roster fetches an hour', function () {
    // QUEUE_CONNECTION=sync: each accepted click runs the job inline, a full Cav fetch, and frees its unique lock.
    $tree = echelonTree();
    $section2 = app(UnitRepository::class)->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $tree['platoon']->id);
    $leader = echelonLeader($tree['platoon']);
    $mock = MockClient::global([
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => MockResponse::make(['profiles' => ['1' => echelonRow('Mine.A', 101)]]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_RESERVE/lite' => MockResponse::make(['profiles' => []]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_ELOA/lite' => MockResponse::make(['profiles' => []]),
    ]);

    $accepted = 0;
    for ($minute = 0; $minute < 10; $minute++) {
        foreach ([$tree['platoon'], $tree['inside'], $section2] as $unit) {
            $accepted += $this->actingAs($leader)->post("/units/{$unit->id}/roster-sync")->isRedirect() ? 1 : 0;
        }
        $this->travel(61)->seconds();
    }

    // Ten minutes of clicks on three nested units: one sync per five minutes, three Cav requests each.
    expect($accepted)->toBe(2)->and(count($mock->getRecordedResponses()))->toBe(6);
});

/** Frees a queued unit sync's unique lock, as the worker does when the job finishes. */
function finishUnitSync(Unit $unit): void
{
    Cache::lock(UniqueLock::getKey(new SyncRosterJob('system', $unit->id)))->forceRelease();
}

it('treats a unit above or below one pending or synced within the cooldown as the same sync', function () {
    Queue::fake();
    $tree = echelonTree();
    $this->actingAs(echelonLeader($tree['platoon']));

    $this->post("/units/{$tree['inside']->id}/roster-sync")->assertRedirect();
    // Pending below it.
    $this->post("/units/{$tree['platoon']->id}/roster-sync")->assertStatus(409);

    finishUnitSync($tree['inside']);
    // Synced below it a moment ago.
    $this->post("/units/{$tree['platoon']->id}/roster-sync")->assertStatus(429)->assertHeader('Retry-After');

    $this->travel(UnitRosterSyncController::COOLDOWN_SECONDS)->seconds();
    $this->post("/units/{$tree['platoon']->id}/roster-sync")->assertRedirect();

    finishUnitSync($tree['platoon']);
    // Synced above it a moment ago.
    $this->post("/units/{$tree['inside']->id}/roster-sync")->assertStatus(429);

    Queue::assertPushed(SyncRosterJob::class, 2);
});

it('lets leaders of unrelated units sync at the same time', function () {
    Queue::fake();
    $tree = echelonTree();
    $insideLeader = echelonLeader($tree['inside']);
    Trooper::factory()->create(['cav_id' => 9002, 'cav_position_id' => 902, 'status' => TrooperStatus::Combat]);
    UnitCommandMapping::factory()->create(['unit_id' => $tree['outside']->id, 'cav_position_id' => 902]);
    $outsideLeader = User::factory()->create(['cav_id' => 9002]);

    $this->actingAs($insideLeader)->post("/units/{$tree['inside']->id}/roster-sync")->assertRedirect();
    $this->actingAs($outsideLeader)->post("/units/{$tree['outside']->id}/roster-sync")->assertRedirect();

    Queue::assertPushed(SyncRosterJob::class, 2);
});

it('does not hold an Admin to the cooldown', function () {
    Queue::fake();
    $tree = echelonTree();
    $this->actingAs(echelonLeader($tree['platoon']))->post("/units/{$tree['inside']->id}/roster-sync")->assertRedirect();
    finishUnitSync($tree['inside']);

    $this->actingAs(User::factory()->admin()->create())->post("/units/{$tree['platoon']->id}/roster-sync")->assertRedirect();

    Queue::assertPushed(SyncRosterJob::class, 2);
});

it('does not let members who may not sync use up the requests leaders can make', function () {
    Queue::fake();
    $tree = echelonTree();
    $leader = echelonLeader($tree['platoon']);

    for ($i = 0; $i < 7; $i++) {
        $member = User::factory()->create();
        for ($j = 0; $j < 3; $j++) {
            $this->actingAs($member)->post("/units/{$tree['inside']->id}/roster-sync")->assertForbidden();
        }
    }

    $this->actingAs($leader)->post("/units/{$tree['inside']->id}/roster-sync")->assertRedirect();
    Queue::assertPushed(SyncRosterJob::class, 1);
});

it('probes the unique lock with a short expiry, so a process dying mid-check cannot block the unit for a day', function () {
    $job = new SyncRosterJob(7, 42);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturnTrue();
    $lock->shouldReceive('release')->once()->andReturnTrue();
    Cache::shouldReceive('lock')->once()
        ->with(UniqueLock::getKey($job), Mockery::on(fn (int $seconds) => $seconds > 0 && $seconds <= 60))
        ->andReturn($lock);

    expect($job->isAlreadyQueued())->toBeFalse();
});

it('decides overlapping syncs one at a time, so two leaders clicking at once start one Cav fetch', function () {
    Queue::fake();
    $tree = echelonTree();
    $platoonLeader = echelonLeader($tree['platoon']);
    Trooper::factory()->create(['cav_id' => 9002, 'cav_position_id' => 902, 'status' => TrooperStatus::Combat]);
    UnitCommandMapping::factory()->create(['unit_id' => $tree['inside']->id, 'cav_position_id' => 902]);
    $sectionLeader = User::factory()->create(['cav_id' => 9002]);

    // The section leader's click arrives just after the platoon leader's request has read the cooldowns and before
    // it has written its own: without one decision at a time, both pass the check and both fetch the roster.
    $raced = false;
    Event::listen(CacheMissed::class, function (CacheMissed $missed) use (&$raced, $sectionLeader, $tree) {
        if ($raced || ! str_starts_with($missed->key, 'unit-roster-sync:synced:')) {
            return;
        }
        $raced = true;
        $this->actingAs($sectionLeader)->post("/units/{$tree['inside']->id}/roster-sync");
    });

    $this->actingAs($platoonLeader)->post("/units/{$tree['platoon']->id}/roster-sync");

    expect($raced)->toBeTrue();
    Queue::assertPushed(SyncRosterJob::class, 1);
});
