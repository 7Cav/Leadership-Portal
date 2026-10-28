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
    /** The id as it was to be stored (an Epic ID lower case, #450), so a caller can look up who owns it. */
    public string $platformId = '';

    /** The `(game_id, platform_id)` already belongs to another trooper — never silently reassigned. */
    public static function alreadyLinked(Game $game, string $platformId, ?Throwable $previous = null): self
    {
        $e = new self(
            "The {$game->name} identity {$platformId} is already linked to another trooper.",
            previous: $previous,
        );
        $e->platformId = $platformId;

        return $e;
    }
}
