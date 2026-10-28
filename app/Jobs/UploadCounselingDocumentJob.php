<?php

namespace App\Jobs;

use App\Commands\Counseling\UploadCounselingDocument;
use App\Commands\Counseling\UploadCounselingDocumentHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued wrapper for copying one counseling document to Google Drive (#381, AD-23): the call to Google stays off the
 * request that filed the record, and a failure is retried with growing pauses (a minute, five, fifteen, an hour)
 * before the job gives up and leaves the record marked `failed`. {@see WithoutOverlapping} keyed on the record keeps
 * two runs from uploading the same document at once.
 *
 * The payload is the record id alone: no document bytes and no credential ever sit on the queue or in `failed_jobs`.
 */
class UploadCounselingDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public readonly UploadCounselingDocument $command) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(UploadCounselingDocumentHandler $handler): void
    {
        $handler->handle($this->command);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("counseling-drive:{$this->command->recordId}"))->releaseAfter(60)->expireAfter(600)];
    }

    /**
     * The handler already marked the record `failed`; this leaves a trace for an operator once every retry is spent. The
     * message is the seam's own wording, which never carries a key, a token or the document.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Counseling document upload to Google Drive exhausted every retry.', [
            'recordId' => $this->command->recordId,
            'exception' => class_basename($e).': '.$e->getMessage(),
        ]);
    }
}
