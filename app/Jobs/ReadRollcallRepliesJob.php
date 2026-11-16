<?php

namespace App\Jobs;

use App\Commands\Attendance\ReadRollcallRepliesHandler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The queued wrapper the scheduler dispatches for the reader of the roll call threads' replies, mirroring
 * {@see SyncLoasJob}: the forum reads stay off the request path, {@see WithoutOverlapping} keeps it to one run at a time,
 * and {@see ShouldBeUnique} drops a second one queued while the first still waits. A run reads a page or two per posted
 * week, so its timeout is short.
 */
class ReadRollcallRepliesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** Releases the uniqueness lock even if a worker is killed mid-run. */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly int|string $triggeredBy = 'system',
    ) {}

    public function handle(ReadRollcallRepliesHandler $handler): void
    {
        $handler->handle($this->triggeredBy);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('rollcall-replies'))->releaseAfter(60)->expireAfter(900)];
    }

    /** The handler already records the failed `sync_runs` row; this leaves a trace once the job gives up for good. */
    public function failed(Throwable $e): void
    {
        Log::error('Roll call reply read exhausted every retry.', [
            'triggeredBy' => $this->triggeredBy,
            'exception' => $e->getMessage(),
        ]);
    }
}
