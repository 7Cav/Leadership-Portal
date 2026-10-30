<?php

namespace App\Jobs;

use App\Commands\StaffReports\PushStaffReportToSheet;
use App\Commands\StaffReports\PushStaffReportToSheetHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued wrapper for copying one sent staff report to the reports Google Sheet: the call to Google stays off the
 * request that sent it, and a failure is retried with growing pauses (a minute, five, fifteen, an hour) before the job
 * gives up and the report reads "Not sent to the sheet yet". {@see WithoutOverlapping} keyed on the report keeps two
 * runs from appending the same row at once.
 *
 * The payload is the report id alone: no report content and no credential ever sit on the queue or in `failed_jobs`.
 */
class PushStaffReportToSheetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public readonly PushStaffReportToSheet $command) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(PushStaffReportToSheetHandler $handler): void
    {
        $handler->handle($this->command);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("staff-report-sheet:{$this->command->reportId}"))->releaseAfter(60)->expireAfter(300)];
    }

    /** A trace for an operator once every retry is spent. The message is the seam's own, which carries no secret. */
    public function failed(Throwable $e): void
    {
        Log::error('Staff report push to the Google Sheet exhausted every retry.', [
            'reportId' => $this->command->reportId,
            'exception' => class_basename($e).': '.$e->getMessage(),
        ]);
    }
}
