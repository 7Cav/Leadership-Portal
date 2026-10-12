<?php

namespace App\Jobs;

use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureSectionPracticeHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued wrapper for one practice's capture (Story 7.5, AD-23): the external reads stay off the
 * request path, and {@see WithoutOverlapping} keyed on the practice means at most one capture run
 * executes per practice — a second dispatch is released and retried, never run alongside. A typed
 * source failure is not an exception here: the handler records it and the practice stays
 * un-captured, so there is nothing to retry. `$tries` exceeds 1 so a released run is not failed
 * by a worker's default `--tries=1` (see `SyncRosterJob`).
 */
class CaptureSectionPracticeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public readonly CaptureSectionPractice $command) {}

    public function handle(CaptureSectionPracticeHandler $handler): void
    {
        $handler->handle($this->command);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("practice-capture:{$this->command->sectionPracticeId}"))->releaseAfter(60)->expireAfter(600)];
    }

    public function failed(Throwable $e): void
    {
        Log::error('Practice capture exhausted every retry.', [
            'sectionPracticeId' => $this->command->sectionPracticeId,
            'exception' => $e->getMessage(),
        ]);
    }
}
