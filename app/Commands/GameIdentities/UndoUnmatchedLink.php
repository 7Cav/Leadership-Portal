<?php

namespace App\Commands\GameIdentities;

use App\Models\Game;
use DateTimeImmutable;

/**
 * A leader undoes a link they just made from the unmatched list (Story 7.7): the player goes back to unmatched, the
 * trooper back to the ID they held before (`$replaced`, or none), and, when the link marked them attended
 * (`$clearMark`), that mark back to what it was (`$restoreMissed`: an automatic `missed`, else unmarked). Handled by
 * {@see UndoUnmatchedLinkHandler}.
 */
final readonly class UndoUnmatchedLink
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
        public Game $game,
        public string $platformId,
        public int $trooperId,
        public ?string $replaced = null,
        public bool $clearMark = false,
        public bool $restoreMissed = false,
    ) {}
}
