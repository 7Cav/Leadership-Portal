<?php

namespace App\Commands\GameIdentities;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * An unmatched player cannot be resolved the way the leader asked (Story 7.7): it is not listed
 * for that practice (any more), the chosen trooper does not fit, or the week is too far back. The
 * message is shown to them.
 *
 * `reason` marks the one refusal the leader can answer on the spot: `has_other_identity`, with the
 * identity the trooper holds now in `current` (`playerName`, `platformId`), so the screen asks
 * "replace or cancel" instead of only showing the message (#176). The others have no reason.
 */
class UnmatchedPlayerResolutionException extends RuntimeException
{
    public const HAS_OTHER_IDENTITY = 'has_other_identity';

    /**
     * @param  array{playerName: string, platformId: string}|null  $current
     */
    public function __construct(string $message, public readonly ?string $reason = null, public readonly ?array $current = null)
    {
        parent::__construct($message);
    }

    public static function notListed(): self
    {
        return new self('That player is no longer listed as unmatched for this practice. Reload to see the current list.');
    }

    /** Undo of a dismissal for a player who is not dismissed (any more): nothing to restore (#176). */
    public static function notDismissed(): self
    {
        return new self('That player is not dismissed, so there is nothing to undo. Reload to see the current list.');
    }

    public static function notInSection(): self
    {
        return new self("Choose a trooper from this section: pre-marking counts in a trooper's own section (AD-14).");
    }

    public static function alreadyHasIdentity(string $playerName, string $platformId, string $gameLabel): self
    {
        return new self(
            "That trooper is already linked to {$playerName} ({$platformId}) on {$gameLabel}. Replace it, or cancel to keep it.",
            self::HAS_OTHER_IDENTITY,
            ['playerName' => $playerName, 'platformId' => $platformId],
        );
    }

    /** The practice's week is older than `WeekOf::floor()`: linking would pre-mark it. */
    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self("The week of {$weekStart->toDateString()} is too far back to change.");
    }
}
