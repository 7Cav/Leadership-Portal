<?php

namespace App\Commands\Units;

use App\Models\Game;
use DomainException;

/**
 * A unit game the domain rules refuse: a game that no longer exists, or one that is retired (#388) and so cannot be
 * newly assigned. The controller turns this into a form error on `game_id`.
 */
class InvalidUnitGameException extends DomainException
{
    public static function noSuchGame(): self
    {
        return new self('That game no longer exists. Reload the page and choose another.');
    }

    public static function retired(Game $game): self
    {
        return new self("{$game->name} is retired, so no unit can be set to play it.");
    }
}
