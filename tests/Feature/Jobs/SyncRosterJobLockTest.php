<?php

use App\Jobs\SyncRosterJob;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Story 2.4, AC #2/#7 — a manual trigger (or a second scheduled tick) fired while a roster
 * sync is already running must be *deferred*, never dropped and never run concurrently.
 * Config-only assertions on the middleware can't catch a regression that breaks the release
 * path itself (e.g. the worker's own `--tries` failing a released job outright), so this drives
 * the real `WithoutOverlapping` lock end to end.
 */
it('releases a job back to the queue, rather than running it, when the roster lock is already held', function () {
    $lock = Cache::store('array')->lock('laravel-queue-overlap:'.SyncRosterJob::class.':roster');
    $lock->get();

    try {
        $job = new SyncRosterJob(1);

        $underlyingJob = Mockery::mock(QueueJobContract::class);
        $underlyingJob->shouldReceive('release')->once()->with(60);
        $job->setJob($underlyingJob);

        $ran = false;
        $job->middleware()[0]->handle($job, function () use (&$ran) {
            $ran = true;
        });

        expect($ran)->toBeFalse();
    } finally {
        $lock->release();
    }
});

it('allows more than one attempt, so a deferred run can actually retry (not fail on release)', function () {
    // A release counts as an attempt; a worker's own `--tries=1` (e.g. `composer dev`'s
    // `queue:listen --tries=1`) would otherwise fail a deferred run outright on its first
    // release, turning "deferred" into "dropped" (see SyncRosterJob's docblock).
    expect((new SyncRosterJob)->tries)->toBeGreaterThan(1);
});

it('bounds the job to a timeout well under the lock\'s hour-long expiry', function () {
    $job = new SyncRosterJob;
    $lockExpiry = $job->middleware()[0]->expiresAfter;

    expect($job->timeout)->toBeGreaterThan(0);
    expect($job->timeout)->toBeLessThan($lockExpiry);
});

it('logs when every retry is exhausted, so a failure has a trace beyond sync_runs (Story 2.8 reads that table, nothing does yet)', function () {
    Log::shouldReceive('error')->once()->with(
        'Roster sync exhausted every retry.',
        ['triggeredBy' => 7, 'exception' => 'Cav API unreachable'],
    );

    (new SyncRosterJob(7))->failed(new Exception('Cav API unreachable'));
});
