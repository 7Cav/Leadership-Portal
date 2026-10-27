<?php

namespace App\Repositories;

use App\Models\Game;
use RuntimeException;
use Throwable;

/**
 * A trooper's in-game identity could not be linked.
 *
 * Translated here, at the layer that owns the database (AD-2), because a CommandHandler may not
 * name `Illuminate\Database\QueryException`. The existing link is always left intact.
 */
class GameIdentityLinkException extends RuntimeException
{
    /** The `(game_id, platform_id)` already belongs to another trooper — never silently reassigned. */
    public static function alreadyLinked(Game $game, string $platformId, ?Throwable $previous = null): self
    {
        return new self(
            "The {$game->name} identity {$platformId} is already linked to another trooper.",
            previous: $previous,
        );
    }
}
