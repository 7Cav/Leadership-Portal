<?php

use App\Jobs\SyncRosterJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/**
 * Story 2.4 — the scheduler is the "system" dispatch path; this only proves it's wired to
 * `SyncRosterJob` on the configured cadence, not that cron itself fires (framework's job).
 */
it('registers SyncRosterJob on the schedule at the configured cadence', function () {
    $this->artisan('list');

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === SyncRosterJob::class);

    expect($event)->not->toBeNull();
    // Read from config, not hardcoded — changing `ROSTER_SYNC_CRON` needs no code change.
    expect($event->getExpression())->toBe(config('sync.roster_cron'));
});

it('defaults ROSTER_SYNC_CRON to hourly when unset', function () {
    expect(config('sync.roster_cron'))->toBe('0 * * * *');
});

it('stamps the scheduled run as triggered by system, not a hardcoded instance the controller could drift from', function () {
    Queue::fake();

    $this->artisan('list');

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === SyncRosterJob::class);

    $event->run($this->app);

    Queue::assertPushed(SyncRosterJob::class, fn (SyncRosterJob $job) => $job->triggeredBy === 'system');
});
