<?php

namespace App\Commands\GameIdentities;

use App\Models\Game;
use RuntimeException;

/** A retired game cannot be newly linked (#388): the identities it already has stay. The message is shown to the leader. */
class GameRetiredException extends RuntimeException
{
    public static function for(Game $game): self
    {
        return new self("{$game->name} is retired, so no identity can be linked to it.");
    }
}
