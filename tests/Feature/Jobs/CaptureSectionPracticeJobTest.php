<?php

use App\Commands\Capture\CaptureSectionPractice;
use App\Jobs\CaptureSectionPracticeJob;
use App\Models\GamePlatform;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

// 7.5 / AD-23: queued, at most one capture run per practice.

function captureJob(int $practiceId): CaptureSectionPracticeJob
{
    $now = new DateTimeImmutable;

    return new CaptureSectionPracticeJob(new CaptureSectionPractice($practiceId, GamePlatform::Hll, $now, $now));
}

it('is queued and allows a released run to retry', function () {
    expect(captureJob(1))->toBeInstanceOf(ShouldQueue::class)
        ->and(captureJob(1)->tries)->toBeGreaterThan(1);
});

it('releases rather than runs when the same practice is already being captured', function () {
    $job = captureJob(5);
    $lock = Cache::store('array')->lock('laravel-queue-overlap:'.CaptureSectionPracticeJob::class.':practice-capture:5');
    $lock->get();

    try {
        $underlying = Mockery::mock(QueueJobContract::class);
        $underlying->shouldReceive('release')->once()->with(60);
        $job->setJob($underlying);

        $ran = false;
        $job->middleware()[0]->handle($job, function () use (&$ran) {
            $ran = true;
        });

        expect($ran)->toBeFalse();
    } finally {
        $lock->release();
    }
});

it('does not block a different practice', function () {
    $lock = Cache::store('array')->lock('laravel-queue-overlap:'.CaptureSectionPracticeJob::class.':practice-capture:5');
    $lock->get();

    try {
        $job = captureJob(6);
        $ran = false;
        $job->middleware()[0]->handle($job, function () use (&$ran) {
            $ran = true;
        });

        expect($ran)->toBeTrue();
    } finally {
        $lock->release();
    }
});
