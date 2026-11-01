<?php

namespace App\Commands\Units;

use App\Models\Game;
use App\Models\Unit;
use DomainException;
use Illuminate\Support\Collection;

/**
 * A unit game the domain rules refuse: a game that no longer exists, or one that is retired (#388) and so cannot be
 * newly assigned, a game set on a unit under one that already has a game, or a game set on a unit with units under it
 * that have games of their own. The controller turns this into a form error on `game_id`.
 */
class InvalidUnitGameException extends DomainException
{
    public static function noSuchGame(): self
    {
        return new self('That game no longer exists. Reload the page and choose another.');
    }

    public static function lockedBy(Unit $ancestor): self
    {
        return new self('This unit plays the game of '.self::label($ancestor).'. Clear the game there first to pick one here.');
    }

    /**
     * @param  Collection<int, Unit>  $below  the units under this one with a game of their own
     */
    public static function setBelow(Collection $below): self
    {
        $labels = $below->map(fn (Unit $unit) => self::label($unit))->values();
        $list = $labels->count() > 1 ? $labels->slice(0, -1)->implode(', ').' and '.$labels->last() : $labels->first();

        return new self("Clear the game on {$list} first.");
    }

    public static function retired(Game $game): self
    {
        return new self("{$game->name} is retired, so no unit can be set to play it.");
    }

    private static function label(Unit $unit): string
    {
        return $unit->callsign ?? $unit->name;
    }
}
