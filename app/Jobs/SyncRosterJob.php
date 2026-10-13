<?php

namespace App\Jobs;

use App\Sync\SyncRosterHandler;
use App\Sync\UnitNoLongerExistsException;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The queued wrapper the scheduler and the manual-trigger controller both dispatch (Story 2.4,
 * AD-23) — a roster fetch (three sequential Cav calls) stays off the request path, and
 * {@see WithoutOverlapping} gives the two dispatch paths one shared lock, so a manual
 * trigger fired mid-scheduled-run is deferred (released back to the queue, retried a minute later)
 * rather than racing it — AD-4's single-writer guarantee would otherwise be at the mercy of
 * whichever caller happens to write last. The lock expires after an hour so a crashed worker can
 * never wedge every future run behind a lock nobody will release.
 *
 * `$tries` must exceed 1: a release counts as an attempt, and the worker's own `--tries` (1 by
 * default, e.g. `composer dev`'s `queue:listen --tries=1`) would otherwise fail a *deferred* run
 * outright on its first release — turning "deferred" into "dropped." `$timeout` bounds a hung
 * external call well under the lock's hour-long expiry, so the lock is freed long before it would
 * otherwise wedge every scheduled tick behind a crashed worker.
 *
 * {@see ShouldBeUnique} guards the queue itself, which the overlap lock above cannot: that lock
 * only decides what a worker does with a job it has already picked up, so ten Admin clicks used
 * to mean ten queued syncs, each deferred and retried in turn. With one `uniqueId` for every
 * dispatch, a second dispatch while a sync is queued, deferred or running is simply not queued.
 * The pending one covers the same scope, so nothing is lost. The unique lock is held
 * until the job finishes or fails, and `$uniqueFor` matches the overlap lock's hour so a crashed
 * worker cannot block every later dispatch for good.
 */
class SyncRosterJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    /** Seconds before the unique lock expires on its own, matching the overlap lock's expiry. */
    public int $uniqueFor = 3600;

    /**
     * `$unitId` narrows the run to one echelon (a leader's manual sync); null is the whole roster.
     */
    public function __construct(
        public readonly int|string $triggeredBy = 'system',
        public readonly ?int $unitId = null,
    ) {}

    public function handle(SyncRosterHandler $handler): void
    {
        try {
            $handler->handle($this->triggeredBy, $this->unitId);
        } catch (UnitNoLongerExistsException $e) {
            // Permanent: retrying cannot bring the unit back, and would hold the unique lock for every attempt.
            $this->fail($e);
        }
    }

    /**
     * One id per scope, whoever triggered it: a scheduled tick and a manual click on the whole roster are the same
     * job, and so are two clicks on one echelon. Different echelons queue separately; the overlap lock below still
     * serialises them with the full sync, which keeps the single-writer guarantee.
     */
    public function uniqueId(): string
    {
        return $this->unitId === null ? 'roster' : "roster:unit:{$this->unitId}";
    }

    /**
     * Whether a sync of this scope is already queued, deferred or running, i.e. whether dispatching it now would be
     * silently dropped by {@see ShouldBeUnique}. Lets a caller tell the user so instead of reporting a sync that was
     * never queued. Advisory: it is a check-then-dispatch, and the unique lock still decides.
     */
    public function isAlreadyQueued(): bool
    {
        $lock = Cache::lock(UniqueLock::getKey($this), 0);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('roster'))->releaseAfter(60)->expireAfter(3600)];
    }

    /**
     * `SyncRosterHandler` already records the failed `sync_runs` row (Story 2.3) — this exists
     * only so exhausting every retry leaves a trace outside that table too. Nothing reads
     * `sync_runs` yet (that's Story 2.8), so without this a run failing five times in a row would
     * be visible only by cross-referencing `failed_jobs` against `sync_runs` by hand.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Roster sync failed for good.', [
            'triggeredBy' => $this->triggeredBy,
            'scope' => $this->uniqueId(),
            'exception' => $e,
        ]);
    }
}
