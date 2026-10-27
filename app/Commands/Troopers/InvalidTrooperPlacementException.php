<?php

namespace App\Commands\Troopers;

use DomainException;

/**
 * A placement the domain rules refuse: the target unit does not exist, or the trooper cannot be placed
 * (no such trooper, already placed, or no longer on a Cav roster). Domain rules, not request shape,
 * so a controller turns this into a form error rather than a 500.
 */
class InvalidTrooperPlacementException extends DomainException
{
    public static function notASection(int $unitId): self
    {
        return new self("There is no unit {$unitId} to place the trooper into.");
    }

    public static function noSuchTrooper(int $trooperId): self
    {
        return new self("There is no trooper {$trooperId}.");
    }

    public static function alreadyPlaced(int $trooperId): self
    {
        return new self("Trooper {$trooperId} is already placed in a unit.");
    }

    public static function departed(int $trooperId): self
    {
        return new self("Trooper {$trooperId} is no longer on a Cav roster, so they cannot be placed.");
    }

    /**
     * The conditional write found the trooper changed between the checks and the write, e.g. a sync
     * placed them a moment ago.
     */
    public static function changedMeanwhile(int $trooperId): self
    {
        return new self("Trooper {$trooperId} was placed or removed from the roster while this was submitted. Reload and check.");
    }
}
