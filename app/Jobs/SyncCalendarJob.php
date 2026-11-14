<?php

namespace App\Jobs;

use App\Sync\SyncCalendarHandler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The queued wrapper the scheduler dispatches for the 7Cav calendar sync (#751), like {@see SyncLoasJob}: the forum read
 * stays off the request path, so opening a roll call never waits on it, and {@see WithoutOverlapping} keeps it to one run
 * at a time. A run is one request, so it is short; a failed one is tried again a few times, and the next hourly run
 * reads the calendar afresh anyway.
 */
class SyncCalendarJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** A minute between tries, so a forum that is down for a moment is not asked again at once. */
    public int $backoff = 60;

    public int $timeout = 120;

    /** Releases the uniqueness lock even if a worker is killed mid-run. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly int|string $triggeredBy = 'system',
    ) {}

    public function uniqueId(): string
    {
        return 'calendar';
    }

    public function handle(SyncCalendarHandler $handler): void
    {
        $handler->handle($this->triggeredBy);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('calendar'))->releaseAfter(60)->expireAfter(600)];
    }

    /** The handler records each failed run in `sync_runs`; this leaves a trace once the job gives up. */
    public function failed(Throwable $e): void
    {
        Log::warning('The calendar sync gave up after its retries.', [
            'triggeredBy' => $this->triggeredBy,
            'exception' => $e->getMessage(),
        ]);
    }
}
