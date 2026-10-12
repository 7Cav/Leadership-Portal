<?php

use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Repositories\SyncRunRepository;

/**
 * Story 2.3 — sole DB access for `sync_runs`.
 */
function syncRunRepository(): SyncRunRepository
{
    return app(SyncRunRepository::class);
}

it('opens a run in_progress with started_at set', function () {
    $run = syncRunRepository()->open('roster', 'system');

    expect($run->source)->toBe('roster')
        ->and($run->status)->toBe(SyncRunStatus::InProgress)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->toBeNull()
        ->and($run->triggered_by)->toBe('system');
});

it('stores a manual trigger as the user id, cast to a string', function () {
    $run = syncRunRepository()->open('roster', 42);

    expect($run->triggered_by)->toBe('42');
});

it('completes a run as success when nothing errored', function () {
    $run = syncRunRepository()->open('roster', 'system');

    syncRunRepository()->complete($run, inserted: 3, updated: 2, inactivated: 1, errored: 0);

    $run->refresh();
    expect($run->status)->toBe(SyncRunStatus::Success)
        ->and($run->inserted)->toBe(3)
        ->and($run->updated)->toBe(2)
        ->and($run->inactivated)->toBe(1)
        ->and($run->errored)->toBe(0)
        ->and($run->finished_at)->not->toBeNull();
});

it('completes a run as partial when rows errored, capturing the error detail', function () {
    $run = syncRunRepository()->open('roster', 'system');

    syncRunRepository()->complete($run, inserted: 0, updated: 0, inactivated: 0, errored: 2, error: '5: unavailable; 6: unavailable');

    $run->refresh();
    expect($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->error)->toBe('5: unavailable; 6: unavailable');
});

it('fails a run, capturing the error and leaving counts at zero', function () {
    $run = syncRunRepository()->open('roster', 'system');

    syncRunRepository()->fail($run, 'The Cav roster API could not be reached.');

    $run->refresh();
    expect($run->status)->toBe(SyncRunStatus::Failure)
        ->and($run->error)->toBe('The Cav roster API could not be reached.')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->inserted)->toBe(0)
        ->and($run->updated)->toBe(0)
        ->and($run->inactivated)->toBe(0);
});

it('finds the latest successful run for a source, ignoring other sources and non-success runs', function () {
    $older = SyncRun::factory()->create(['source' => 'roster', 'finished_at' => now()->subDay()]);
    $newer = SyncRun::factory()->create(['source' => 'roster', 'finished_at' => now()]);
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Failure, 'finished_at' => now()->addDay()]);
    SyncRun::factory()->create(['source' => 'loa', 'finished_at' => now()->addDays(2)]);

    expect(syncRunRepository()->latestSuccessful('roster')->id)->toBe($newer->id);
});

it('returns null when a source has no successful run', function () {
    expect(syncRunRepository()->latestSuccessful('roster'))->toBeNull();
});

it('breaks a tie on finished_at by the most recent id', function () {
    $sameSecond = now();
    SyncRun::factory()->create(['source' => 'roster', 'finished_at' => $sameSecond]);
    $latest = SyncRun::factory()->create(['source' => 'roster', 'finished_at' => $sameSecond]);

    expect(syncRunRepository()->latestSuccessful('roster')->id)->toBe($latest->id);
});

it('finds the latest closed run of any outcome, skipping one still in progress (2.8)', function () {
    SyncRun::factory()->create(['source' => 'roster', 'finished_at' => now()->subDay()]);
    $failed = SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Failure, 'finished_at' => now()]);
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::InProgress, 'finished_at' => null]);
    SyncRun::factory()->create(['source' => 'loa', 'finished_at' => now()->addDay()]);

    expect(syncRunRepository()->latestFinished('roster')->id)->toBe($failed->id);
});

it('returns null when a source has never finished a run', function () {
    expect(syncRunRepository()->latestFinished('roster'))->toBeNull();
});
