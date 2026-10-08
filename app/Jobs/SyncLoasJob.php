<?php

namespace App\Jobs;

use App\Sync\SyncLoasHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The queued wrapper the scheduler and the manual-trigger controller both dispatch for the leave sync
 * (Story 4.2, AD-23), mirroring {@see SyncRosterJob}: the forum reads stay off the request path, and
 * {@see WithoutOverlapping} gives both paths one lock, keyed on the source, so at most one leave sync runs
 * at a time and a manual trigger fired mid-run is deferred rather than racing it (AD-4's single writer).
 *
 * `$timeout` is far longer than the roster job's: the first run backfills a year of threads with one forum
 * request each, paced by a pause between requests. It still sits well under the lock's hour-long expiry, so
 * a crashed worker cannot wedge every later run. `$tries` must exceed 1 for the same reason as the roster
 * job: a deferral counts as an attempt.
 */
class SyncLoasJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 900;

    public function __construct(
        public readonly int|string $triggeredBy = 'system',
        public readonly bool $full = false,
    ) {}

    /**
     * Whether the forum can be read for leave at all: an API key and at least one leave section. The
     * scheduler only runs the sync once it is, so an unconfigured install does not record a failed run
     * every hour.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.sevencav.api_key')) && config('services.sevencav.loa_nodes', []) !== [];
    }

    public function handle(SyncLoasHandler $handler): void
    {
        $handler->handle($this->triggeredBy, $this->full);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('loa'))->releaseAfter(60)->expireAfter(3600)];
    }

    /**
     * `SyncLoasHandler` already records the failed `sync_runs` row; this leaves a trace outside that table
     * once every retry is spent.
     */
    public function failed(Throwable $e): void
    {
        Log::error('LOA sync exhausted every retry.', [
            'triggeredBy' => $this->triggeredBy,
            'exception' => $e->getMessage(),
        ]);
    }
}
