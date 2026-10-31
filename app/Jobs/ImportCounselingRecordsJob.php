<?php

namespace App\Jobs;

use App\Commands\Counseling\ImportCounselingBatch;
use App\Commands\Counseling\ImportCounselingBatchHandler;
use App\Commands\Counseling\ImportRefusedException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued wrapper for the counseling import (#565, AD-23): an Admin's checked batch stored off the request path, since a
 * batch of up to 200 PDFs takes longer than a request should. It only invokes {@see ImportCounselingBatchHandler},
 * which records how the run ended (finished, refused or failed, with the report) for the import page to show.
 *
 * Run once: the batch is marked queued before dispatch and the handler runs only a queued batch, and the import keys
 * keep anything stored from being stored twice anyway. {@see WithoutOverlapping} keyed on the batch keeps a second
 * dispatch from running alongside. When the queue itself gives up (a timeout, a dead worker), {@see failed()} marks the
 * batch failed, so the page never waits forever on a run that is gone.
 */
class ImportCounselingRecordsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly ImportCounselingBatch $command) {}

    public function handle(ImportCounselingBatchHandler $handler): void
    {
        try {
            $handler->handle($this->command);
        } catch (ImportRefusedException $e) {
            // A batch gone stale or already run: its page already says so, and nothing is retried.
            Log::warning('Counseling import not run.', ['batch' => $this->command->batch, 'reason' => $e->getMessage()]);
        }
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("counseling-import:{$this->command->batch}"))->dontRelease()->expireAfter(1200)];
    }

    public function failed(Throwable $e): void
    {
        Log::error('Counseling import failed.', ['batch' => $this->command->batch, 'exception' => $e->getMessage()]);

        app(ImportCounselingBatchHandler::class)->markFailed(
            $this->command->batch,
            'The import stopped before it finished (the server gave up on it). Records stored before that are kept; check the log, then import the CSV again.',
        );
    }
}
