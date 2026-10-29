<?php

namespace App\Commands\Units;

use DomainException;

/**
 * An acting-lead change the domain rules refuse: a unit that does not exist or is not a line unit below the regiment,
 * a trooper who does not exist or no longer serves, the Admin themselves, a unit that already has another acting lead
 * or a serving billet holder as its lead, or one with none to remove. Domain rules, not request shape, so the
 * controller turns this into a form error.
 */
class InvalidActingLeadException extends DomainException
{
    public static function noSuchUnit(int $unitId): self
    {
        return new self('That unit no longer exists. Reload the page.');
    }

    public static function notLeadable(string $unit): self
    {
        return new self("{$unit} cannot take an acting lead: only a line unit below the regiment can.");
    }

    public static function noSuchTrooper(int $trooperId): self
    {
        return new self('That trooper no longer exists. Reload the page.');
    }

    public static function notServing(string $trooper): self
    {
        return new self("{$trooper} is no longer on a Cav roster, so they cannot lead a unit.");
    }

    public static function self(): self
    {
        return new self('You cannot make yourself an acting lead. Another Admin can.');
    }

    public static function ledByBillet(string $unit, string $trooper): self
    {
        return new self("{$unit} is led by {$trooper} on their billet, so it takes no acting lead.");
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
