<?php

namespace App\Commands\GameIdentities;

use App\Models\GameIdentityText;
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
 * The name, and the ID inside the message, are shown with any hidden character written out
 * ({@see GameIdentityText::reveal()}), so a direction override cannot reorder the sentence; the
 * `current` platform ID stays as stored, since the screen sends it back as `replacing`.
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
        return new self("Choose a trooper from this section: a pre-mark counts only in a trooper's own section.");
    }

    public static function alreadyHasIdentity(string $playerName, string $platformId, string $gameLabel): self
    {
        $name = GameIdentityText::reveal($playerName);
        $shownId = GameIdentityText::reveal($platformId);

        return new self(
            "That trooper is already linked to {$name} ({$shownId}) on {$gameLabel}. Replace it, or cancel to keep it.",
            self::HAS_OTHER_IDENTITY,
            ['playerName' => $name, 'platformId' => $platformId],
        );
    }

    /** The practice's week is older than `WeekOf::floor()`: linking would pre-mark it. */
    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self("The week of {$weekStart->format('dMy')} is too far back to change.");
    }
}
