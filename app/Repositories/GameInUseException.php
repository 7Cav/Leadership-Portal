<?php

namespace App\Repositories;

use App\Models\Game;
use RuntimeException;
use Throwable;

/**
 * A game something uses (an identity, a captured player, a dismissal) cannot be deleted, only retired (#388).
 * Raised here, at the layer that owns the database (AD-2), so a foreign-key refusal reads the same as the check.
 */
class GameInUseException extends RuntimeException
{
    public static function for(Game $game, ?Throwable $previous = null): self
    {
        return new self("{$game->name} is in use, so it cannot be deleted. Retire it instead.", previous: $previous);
    }
}
