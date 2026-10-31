<?php

namespace App\Jobs;

use App\Commands\Counseling\ImportCounselingBatch;
use App\Commands\Counseling\ImportCounselingBatchHandler;
use App\Commands\Counseling\ImportRefusedException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued wrapper for the counseling import (#565, AD-23): an Admin's checked batch stored off the request path, since a
 * batch of up to 200 PDFs takes longer than a request should. It only invokes {@see ImportCounselingBatchHandler}, which
 * deletes the batch when done. Run once: a re-run of a half-finished batch would find its batch deleted, and the
 * import keys keep anything stored from being stored twice anyway. {@see WithoutOverlapping} keyed on the batch keeps
 * a double-click from running it twice at once.
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
        } catch (ImportRefusedException|AuthorizationException $e) {
            // A batch already imported (or gone stale), or an actor no longer allowed: nothing to retry.
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
    }
}
