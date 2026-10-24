<?php

namespace App\Commands\Units;

use DomainException;

/**
 * An acting-lead change the domain rules refuse: a unit that does not exist or is not a line unit below the regiment,
 * a trooper who does not exist or no longer serves, a unit that already has another acting lead, or one with none to
 * remove. Domain rules, not request shape, so the controller turns this into a form error.
 */
class InvalidActingLeadException extends DomainException
{
    public static function noSuchUnit(int $unitId): self
    {
        return new self("There is no unit {$unitId}.");
    }

    public static function notLeadable(string $unit): self
    {
        return new self("{$unit} cannot take an acting lead: only a line unit below the regiment can.");
    }

    public static function noSuchTrooper(int $trooperId): self
    {
        return new self("There is no trooper {$trooperId}.");
    }

    public static function notServing(string $trooper): self
    {
        return new self("{$trooper} is no longer on a Cav roster, so they cannot lead a unit.");
    }

    public static function alreadyLed(string $unit, string $trooper): self
    {
        return new self("{$unit} already has {$trooper} as its acting lead. Remove them first.");
    }

    public static function noneToRemove(string $unit): self
    {
        return new self("{$unit} has no acting lead to remove.");
    }
}
