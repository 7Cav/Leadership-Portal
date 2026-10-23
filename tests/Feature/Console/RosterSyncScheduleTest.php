<?php

use App\Jobs\SyncRosterJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

/**
 * Story 2.4 — the scheduler is the "system" dispatch path, through `portal:sync-roster`. This proves the command
 * is on the schedule hourly and queues the job stamped as `system`, not that cron itself fires (framework's job).
 */
function rosterSyncEvent()
{
    test()->artisan('list');

    return collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'portal:sync-roster'));
}

it('schedules portal:sync-roster hourly, on the hour', function () {
    $event = rosterSyncEvent();

    expect($event)->not->toBeNull()
        ->and($event->getExpression())->toBe('0 * * * *')
        ->and($event->filtersPass($this->app))->toBeTrue();
});

it('queues the roster sync stamped as triggered by system, not a hardcoded instance the controller could drift from', function () {
    Queue::fake();

    $this->artisan('portal:sync-roster')
        ->expectsOutput('Roster sync queued.')
        ->assertSuccessful();

    Queue::assertPushed(SyncRosterJob::class, fn (SyncRosterJob $job) => $job->triggeredBy === 'system' && $job->unitId === null);
});

it('runs the roster sync in the foreground with --now', function () {
    Bus::fake();

    $this->artisan('portal:sync-roster', ['--now' => true])
        ->expectsOutput('Roster sync finished.')
        ->assertSuccessful();

    Bus::assertDispatchedSync(SyncRosterJob::class, fn (SyncRosterJob $job) => $job->triggeredBy === 'system');
});
