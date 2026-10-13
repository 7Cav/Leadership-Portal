<?php

use App\Jobs\CaptureSectionPracticeJob;
use App\Jobs\SyncLoasJob;
use App\Jobs\SyncRosterJob;

// A worker treats a reserved job as stuck once `retry_after` passes and hands it to another
// worker. If that window is shorter than a job's own timeout, a job that is still running gets
// reserved again: it burns its tries and runs twice. So the window must outlast every job.

it('keeps the queue retry window above every job timeout', function (string $connection, string $job) {
    $timeout = (new ReflectionClass($job))->getProperty('timeout')->getDefaultValue();

    expect(config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan($timeout);
})->with(['database', 'redis'])->with([SyncLoasJob::class, SyncRosterJob::class, CaptureSectionPracticeJob::class]);
