<?php

namespace App\Jobs;

use App\Sync\SyncRosterHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

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
 */
class SyncRosterJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public readonly int|string $triggeredBy = 'system') {}

    public function handle(SyncRosterHandler $handler): void
    {
        $handler->handle($this->triggeredBy);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('roster'))->releaseAfter(60)->expireAfter(3600)];
    }
}
