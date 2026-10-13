<?php

namespace App\Commands\GameIdentities;

use RuntimeException;

/**
 * An unmatched player cannot be resolved the way the leader asked (Story 7.7): it is not listed
 * for that practice (any more), or the chosen trooper does not fit. The message is shown to them.
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
}
