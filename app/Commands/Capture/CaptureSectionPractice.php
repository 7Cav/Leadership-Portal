<?php

namespace App\Commands\Capture;

use App\Support\Audit\ActingSnapshot;
use DateTimeImmutable;

/**
 * Capture who was on the game server during one section practice's window (7.5): stamp the
 * practice and resolve the observed players to troopers. Bounded to `[windowStart, windowEnd]` —
 * the scheduled practice window, never an open-ended poll. `$triggeredBy` is a user id (manual) or
 * the literal `'system'` (scheduler), as on `sync_runs`. Handled by {@see CaptureSectionPracticeHandler}.
 *
 * The game is named by id and read when the run starts (#388), not carried as a model: the command
 * is queued, and an Admin may change the game's telemetry interface before a run or its retries.
 *
 * `$acting` is who was really acting when a "Try again" queued it ({@see ActingSnapshot}): the job runs later with no
 * request behind it, so the handler holds it again while it runs and the entries name the Admin or leader, on behalf
 * of the person (or in the billet's place), exactly as the request would have.
 */
final readonly class CaptureSectionPractice
{
    public function __construct(
        public int $sectionPracticeId,
        public int $gameId,
        public DateTimeImmutable $windowStart,
        public DateTimeImmutable $windowEnd,
        public int|string $triggeredBy = 'system',
        public ?ActingSnapshot $acting = null,
    ) {}
}
