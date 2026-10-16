<?php

namespace App\Commands\GameIdentities;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * An unmatched player cannot be resolved the way the leader asked (Story 7.7): it is not listed
 * for that practice (any more), the chosen trooper does not fit, or the week is too far back. The
 * message is shown to them.
 */
class UnmatchedPlayerResolutionException extends RuntimeException
{
    public static function notListed(): self
    {
        return new self('That player is no longer listed as unmatched for this practice. Reload to see the current list.');
    }

    public static function notInSection(): self
    {
        return new self("Choose a trooper from this section: pre-marking counts in a trooper's own section (AD-14).");
    }

    public static function alreadyHasIdentity(string $playerName, string $gameLabel): self
    {
        return new self("That trooper is already linked to {$playerName} on {$gameLabel}. Change it on the Game identities screen first.");
    }

    /** The practice's week is older than `WeekOf::floor()`: linking would pre-mark it. */
    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self("The week of {$weekStart->toDateString()} is too far back to change.");
    }
}
