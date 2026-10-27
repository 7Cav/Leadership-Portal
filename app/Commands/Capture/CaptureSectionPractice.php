<?php

namespace App\Commands\Capture;

use App\Models\Game;
use DateTimeImmutable;

/**
 * Capture who was on the game server during one section practice's window (7.5): stamp the
 * practice and resolve the observed players to troopers. Bounded to `[windowStart, windowEnd]` —
 * the scheduled practice window, never an open-ended poll. `$triggeredBy` is a user id (manual) or
 * the literal `'system'` (scheduler), as on `sync_runs`. Handled by {@see CaptureSectionPracticeHandler}.
 */
final readonly class CaptureSectionPractice
{
    public function __construct(
        public int $sectionPracticeId,
        public Game $game,
        public DateTimeImmutable $windowStart,
        public DateTimeImmutable $windowEnd,
        public int|string $triggeredBy = 'system',
    ) {}
}
