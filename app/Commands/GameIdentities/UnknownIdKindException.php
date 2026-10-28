<?php

namespace App\Commands\GameIdentities;

use App\Models\Game;
use RuntimeException;

/** An identity can only be entered as one of its game's ID kinds (#428). The message is shown to the leader. */
class UnknownIdKindException extends RuntimeException
{
    public static function for(Game $game, string $kind): self
    {
        return new self("{$game->name} has no ID kind \"{$kind}\".");
    }
}
