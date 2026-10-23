<?php

use App\Jobs\SyncLoasJob;
use App\Jobs\SyncRosterJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
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

/** The scheduled `portal:sync-loas` runs, the incremental one unless `$full`. */
function loasEvent(bool $full = false)
{
    test()->artisan('list');

    return collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'portal:sync-loas')
        && str_contains((string) $event->command, '--full') === $full);
}

describe('on the schedule', function () {

    it('runs portal:sync-loas at a quarter past the hour, offset from the roster run', function () {
        test()->artisan('list');
        $roster = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'portal:sync-roster'));

        expect(loasEvent())->not->toBeNull()
            ->and(loasEvent()->getExpression())->toBe('15 * * * *')
            ->and(loasEvent()->getExpression())->not->toBe($roster->getExpression());
    });

    it('does not run until the forum key and a leave section are configured', function (?string $key, array $nodes) {
        config(['services.sevencav.api_key' => $key, 'services.sevencav.loa_nodes' => $nodes]);

        expect(loasEvent()->filtersPass($this->app))->toBeFalse()
            ->and(loasEvent(full: true)->filtersPass($this->app))->toBeFalse()
            ->and(SyncLoasJob::isConfigured())->toBeFalse();
    })->with([
        'nothing' => [null, []],
        'no key' => [null, [180 => 'loa']],
        'empty key' => ['', [180 => 'loa']],
        'no section' => ['forum-key', []],
    ]);

    it('runs once both are configured', function () {
        config(['services.sevencav.api_key' => 'forum-key', 'services.sevencav.loa_nodes' => [180 => 'loa']]);

        expect(loasEvent()->filtersPass($this->app))->toBeTrue()
            ->and(loasEvent(full: true)->filtersPass($this->app))->toBeTrue()
            ->and(SyncLoasJob::isConfigured())->toBeTrue();
    });

    it('also has a daily full run at 03:30, a different time from the hourly ones', function () {
        expect(loasEvent(full: true))->not->toBeNull()
            ->and(loasEvent(full: true)->getExpression())->toBe('30 3 * * *');
    });
});

describe('portal:sync-loas', function () {

    beforeEach(fn () => config(['services.sevencav.api_key' => 'forum-key', 'services.sevencav.loa_nodes' => [180 => 'loa']]));

    it('queues an incremental sync stamped as triggered by system', function () {
        Queue::fake();

        $this->artisan('portal:sync-loas')->expectsOutput('LOA sync queued.')->assertSuccessful();

        Queue::assertPushed(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->triggeredBy === 'system' && $job->full === false);
    });

    it('queues a full run with --full', function () {
        Queue::fake();

        $this->artisan('portal:sync-loas', ['--full' => true])->expectsOutput('Full LOA sync queued.')->assertSuccessful();

        Queue::assertPushed(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->triggeredBy === 'system' && $job->full === true);
    });

    it('runs in the foreground with --now', function () {
        Bus::fake();

        $this->artisan('portal:sync-loas', ['--now' => true])->expectsOutput('LOA sync finished.')->assertSuccessful();

        Bus::assertDispatchedSync(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->triggeredBy === 'system' && $job->full === false);
    });

    it('refuses, queueing nothing, until the forum key and a leave section are configured', function () {
        config(['services.sevencav.api_key' => null]);
        Queue::fake();

        $this->artisan('portal:sync-loas')
            ->expectsOutput('The LOA sync is not configured. Set SEVENCAV_FORUM_API_KEY and SEVENCAV_FORUM_LOA_NODES in .env.')
            ->assertFailed();

        Queue::assertNothingPushed();
    });
});

it('is unique per kind, so repeated triggers do not stack, while a full run is its own kind', function () {
    expect((new SyncLoasJob(1))->uniqueId())->toBe((new SyncLoasJob('system'))->uniqueId())
        ->and((new SyncLoasJob(1, true))->uniqueId())->not->toBe((new SyncLoasJob(1))->uniqueId())
        ->and(new SyncLoasJob)->toBeInstanceOf(ShouldBeUnique::class);
});
