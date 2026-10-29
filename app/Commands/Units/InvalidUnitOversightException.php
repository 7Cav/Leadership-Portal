<?php

namespace App\Commands\Units;

use DomainException;

/**
 * An oversight change the domain rules refuse: a unit that does not exist or takes no overseer, a trooper who does not
 * exist or no longer serves, the overseer it already has, or none to clear. Domain rules, not request shape, so the
 * controller turns this into a form error.
 */
class InvalidUnitOversightException extends DomainException
{
    public static function noSuchUnit(): self
    {
        return new self('That unit no longer exists. Reload the page.');
    }

    public static function notOverseeable(string $unit): self
    {
        return new self("{$unit} cannot have an overseer: only a battalion or the Reservists can.");
    }

    public static function noSuchTrooper(): self
    {
        return new self('That trooper no longer exists. Reload the page.');
    }

    public static function notServing(string $trooper): self
    {
        return new self("{$trooper} is no longer on a Cav roster, so they cannot oversee a unit.");
    }

    public static function unchanged(string $unit, string $trooper): self
    {
        return new self("{$trooper} already oversees {$unit}.");
    }

    public static function noneToClear(string $unit): self
    {
        return new self("{$unit} has no overseer to clear.");
    }
}
