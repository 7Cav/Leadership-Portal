<?php

namespace App\Jobs;

use App\Sync\SyncLoasHandler;
use DateTimeInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The queued wrapper the scheduler and the manual-trigger controller both dispatch for the leave sync
 * (Story 4.2, AD-23), mirroring {@see SyncRosterJob}: the forum reads stay off the request path, and
 * {@see WithoutOverlapping} gives both paths one lock, keyed on the source, so at most one leave sync runs
 * at a time and a manual trigger fired mid-run is deferred rather than racing it (AD-4's single writer).
 *
 * `$timeout` is far longer than the roster job's: a run going back through the year reads one forum thread per
 * request, paced by a pause between requests (the handler stops itself well before this). It still sits well
 * under the lock's hour-long expiry, so a crashed worker cannot wedge every later run.
 *
 * A deferral (the lock held by another run) is a release, and a release is an attempt, so a count of tries would
 * drop a sync that was only waiting its turn: with five tries a minute apart, a click during a long run used to be
 * given up after about five minutes. So, like the roster job, it waits by time ({@see retryUntil()}), and
 * `$maxExceptions` caps real failures.
 *
 * {@see ShouldBeUnique}: a click (or several) while a sync of the same kind is already queued or running adds
 * nothing, so repeated triggers cannot stack up jobs that wait on the lock. An incremental and a full sync are
 * separate kinds. A sync of the same kind that is already running may have read past what the click wanted, so
 * the manual trigger then asks for one follow-up (`$followUp`): a kind of its own, so there is at most one, which
 * waits for the running sync and runs after it.
 */
class SyncLoasJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Unbounded by count: see the class note. {@see retryUntil()} bounds the wait, `$maxExceptions` real failures. */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 900;

    /** Releases the uniqueness lock even if a worker is killed mid-run. */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int|string $triggeredBy = 'system',
        public readonly bool $full = false,
        public readonly bool $followUp = false,
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

    public function uniqueId(): string
    {
        return ($this->full ? 'loa-full' : 'loa').($this->followUp ? ':next' : '');
    }

    /**
     * How long a sync may wait behind other runs before it is given up on: room for a run at its full timeout and
     * another queued ahead of this one.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(60);
    }

    /**
     * Whether a sync of this kind is already queued, deferred or running, so dispatching it now would be dropped by
     * {@see ShouldBeUnique}. It only reads whether the unique lock is held and never takes it, for the reason
     * {@see SyncRosterJob::isAlreadyQueued()} gives. Advisory: the unique lock still decides.
     */
    public function isAlreadyQueued(): bool
    {
        return Cache::lock(UniqueLock::getKey($this))->isLocked();
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
     * once the job gives up for good: its third exception, or its wait running out.
     */
    public function failed(Throwable $e): void
    {
        Log::error('LOA sync exhausted every retry.', [
            'triggeredBy' => $this->triggeredBy,
            'exception' => $e->getMessage(),
        ]);
    }
}
