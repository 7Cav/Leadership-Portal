<?php

namespace App\Commands\Units;

use App\Models\Game;
use DomainException;

/**
 * A unit game the domain rules refuse: a game that no longer exists, or one that is retired (#388) and so cannot be
 * newly assigned, or a game set below battalion level, where a unit plays the game it inherits. The controller turns this into a form error on `game_id`.
 */
class InvalidUnitGameException extends DomainException
{
    public static function noSuchGame(): self
    {
        return new self('That game no longer exists. Reload the page and choose another.');
    }

    public static function belowBattalion(): self
    {
        return new self('Only a battalion or a unit above it picks a game. This unit plays the game of the battalion it sits under.');
    }

    public static function retired(Game $game): self
    {
        return new self("{$game->name} is retired, so no unit can be set to play it.");
    }
}
