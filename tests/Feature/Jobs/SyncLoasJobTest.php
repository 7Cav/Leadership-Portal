<?php

use App\Jobs\SyncLoasJob;
use App\Jobs\SyncRosterJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

// Story 4.2: the leave sync's queued wrapper and its place on the schedule, mirroring the roster job's.

it('releases a job back to the queue, rather than running it, when the leave lock is already held', function () {
    $lock = Cache::store('array')->lock('laravel-queue-overlap:'.SyncLoasJob::class.':loa');
    $lock->get();

    try {
        $job = new SyncLoasJob(1);

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

it('does not wait on a roster sync: the two sources lock separately', function () {
    $lock = Cache::store('array')->lock('laravel-queue-overlap:'.SyncRosterJob::class.':roster');
    $lock->get();

    try {
        $ran = false;
        $job = new SyncLoasJob(1);
        $job->middleware()[0]->handle($job, function () use (&$ran) {
            $ran = true;
        });

        expect($ran)->toBeTrue();
    } finally {
        $lock->release();
    }
});

it('allows more than one attempt so a deferred run can retry, and a timeout under the lock expiry', function () {
    $job = new SyncLoasJob;

    expect($job->tries)->toBeGreaterThan(1)
        ->and($job->timeout)->toBeGreaterThan(0)
        ->and($job->timeout)->toBeLessThan($job->middleware()[0]->expiresAfter);
});

it('logs when every retry is exhausted', function () {
    Log::shouldReceive('error')->once()->with(
        'LOA sync exhausted every retry.',
        ['triggeredBy' => 7, 'exception' => 'Forum unreachable'],
    );

    (new SyncLoasJob(7))->failed(new Exception('Forum unreachable'));
});

it('carries who triggered it and whether it is a full run', function () {
    expect((new SyncLoasJob)->triggeredBy)->toBe('system')
        ->and((new SyncLoasJob)->full)->toBeFalse()
        ->and((new SyncLoasJob(3, true))->triggeredBy)->toBe(3)
        ->and((new SyncLoasJob(3, true))->full)->toBeTrue();
});

function loasEvent()
{
    test()->artisan('list');

    return collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === SyncLoasJob::class);
}

describe('on the schedule', function () {

    it('is registered at the configured cadence, offset from the roster run', function () {
        $event = loasEvent();

        expect($event)->not->toBeNull()
            ->and($event->getExpression())->toBe(config('sync.loa_cron'))
            ->and(config('sync.loa_cron'))->not->toBe(config('sync.roster_cron'));
    });

    it('defaults to a quarter past the hour', function () {
        expect(config('sync.loa_cron'))->toBe('15 * * * *');
    });

    it('does not run until the forum key and a leave section are configured', function (?string $key, array $nodes) {
        config(['services.sevencav.api_key' => $key, 'services.sevencav.loa_nodes' => $nodes]);

        expect(loasEvent()->filtersPass($this->app))->toBeFalse()
            ->and(SyncLoasJob::isConfigured())->toBeFalse();
    })->with([
        'nothing' => [null, []],
        'no key' => [null, [180 => 'loa']],
        'empty key' => ['', [180 => 'loa']],
        'no section' => ['forum-key', []],
    ]);

    it('also has a daily full run, a different time from the hourly ones', function () {
        $this->artisan('list');
        $full = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === SyncLoasJob::class && $event->getExpression() === config('sync.loa_full_cron'));

        expect($full)->not->toBeNull()
            ->and(config('sync.loa_full_cron'))->toBe('30 3 * * *')
            ->and(config('sync.loa_full_cron'))->not->toBe(config('sync.loa_cron'));
    });

    it('runs the daily one as a full run, and only once configured', function () {
        Queue::fake();
        $this->artisan('list');
        $full = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === SyncLoasJob::class && $event->getExpression() === config('sync.loa_full_cron'));

        config(['services.sevencav.api_key' => null, 'services.sevencav.loa_nodes' => []]);
        expect($full->filtersPass($this->app))->toBeFalse();

        config(['services.sevencav.api_key' => 'forum-key', 'services.sevencav.loa_nodes' => [180 => 'loa']]);
        expect($full->filtersPass($this->app))->toBeTrue();

        $full->run($this->app);

        Queue::assertPushed(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->triggeredBy === 'system' && $job->full === true);
    });

    it('runs once both are configured, stamped as triggered by system', function () {
        config(['services.sevencav.api_key' => 'forum-key', 'services.sevencav.loa_nodes' => [180 => 'loa']]);
        Queue::fake();

        $event = loasEvent();

        expect($event->filtersPass($this->app))->toBeTrue()
            ->and(SyncLoasJob::isConfigured())->toBeTrue();

        $event->run($this->app);

        Queue::assertPushed(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->triggeredBy === 'system' && $job->full === false);
    });
});

it('is unique per kind, so repeated triggers do not stack, while a full run is its own kind', function () {
    expect((new SyncLoasJob(1))->uniqueId())->toBe((new SyncLoasJob('system'))->uniqueId())
        ->and((new SyncLoasJob(1, true))->uniqueId())->not->toBe((new SyncLoasJob(1))->uniqueId())
        ->and(new SyncLoasJob)->toBeInstanceOf(ShouldBeUnique::class);
});
