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

// A mass parse failure once built a summary of about 100KB, past a MySQL `text` column: the close
// failed, then the failure record failed the same way, and the run stayed `in_progress`.
it('keeps the first 2,000 characters of an over-long error when completing or failing a run', function (string $close) {
    $run = syncRunRepository()->open('roster', 'system');
    $error = str_repeat('é', 70000);

    $close === 'complete'
        ? syncRunRepository()->complete($run, inserted: 0, updated: 0, inactivated: 0, errored: 1, error: $error)
        : syncRunRepository()->fail($run, $error);

    $run->refresh();
    expect($run->status)->not->toBe(SyncRunStatus::InProgress)
        ->and($run->error)->toBe(str_repeat('é', 2000));
})->with(['complete', 'fail']);

it('closes orphaned in_progress runs of one source only', function () {
    $roster = syncRunRepository()->open('roster', 'system');
    $loa = syncRunRepository()->open('loa', 'system');

    expect(syncRunRepository()->failOrphaned('roster'))->toBe(1);

    expect($roster->fresh()->status)->toBe(SyncRunStatus::Failure)
        ->and($roster->fresh()->finished_at)->not->toBeNull()
        ->and($loa->fresh()->status)->toBe(SyncRunStatus::InProgress);
});

it('closes only the orphaned runs of the practice it is given', function () {
    $mine = syncRunRepository()->open('capture', 'system', 11);
    $other = syncRunRepository()->open('capture', 'system', 12);

    expect(syncRunRepository()->failOrphaned('capture', 11))->toBe(1);

    expect($mine->fresh()->status)->toBe(SyncRunStatus::Failure)
        ->and($other->fresh()->status)->toBe(SyncRunStatus::InProgress);
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

it('saves a cursor while a run is going, and hands back the latest one saved for the source, whatever that run became', function () {
    $this->travelTo(now()->startOfHour());
    $older = syncRunRepository()->open('loa', 'system');
    syncRunRepository()->saveCursor($older, ['sections' => [180 => ['page' => 3]]]);

    $this->travelTo(now()->addHour());
    $killed = syncRunRepository()->open('loa', 'system');
    syncRunRepository()->saveCursor($killed, ['sections' => [180 => ['page' => 9]]]);
    syncRunRepository()->failOrphaned('loa');

    // A later run that saved none (a full LOA run), and another source's cursor, are passed over.
    $this->travelTo(now()->addHour());
    syncRunRepository()->complete(syncRunRepository()->open('loa', 'system'), 0, 0, 0, 0);
    syncRunRepository()->saveCursor(syncRunRepository()->open('roster', 'system'), ['other' => true]);

    expect(syncRunRepository()->latestCursor('loa'))->toBe(['sections' => [180 => ['page' => 9]]])
        ->and(syncRunRepository()->latestCursor('capture'))->toBeNull();
});
