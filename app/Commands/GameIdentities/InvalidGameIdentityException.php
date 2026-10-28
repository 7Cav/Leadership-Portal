<?php

namespace App\Commands\GameIdentities;

use App\Models\IdKindFormat;
use RuntimeException;

/**
 * A player name or ID that cannot be stored as given (#450): empty once its hidden characters are removed, or an ID
 * without the shape its kind has. `$field` is the form field the message belongs on. The message is shown to the leader.
 */
class InvalidGameIdentityException extends RuntimeException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function emptyName(): self
    {
        return new self('The player name is only hidden characters. Type the name as it shows in the game.', 'player_name');
    }

    public static function emptyId(): self
    {
        return new self('The ID is only hidden characters. Copy it from the game again.', 'platform_id');
    }

    public static function badFormat(IdKindFormat $format): self
    {
        return new self($format->message(), 'platform_id');
    }

    /** A server-reported ID that cleaning would change: stored changed, the capture would no longer match it. */
    public static function reportedIdNotClean(): self
    {
        return new self('The ID this player was reported with has hidden characters, so it cannot be linked. Mark this trooper by hand.', 'platform_id');
    }
}
