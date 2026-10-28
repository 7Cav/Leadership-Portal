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
        return new self('That unit no longer exists. Reload the page and choose another.');
    }

    public static function noSuchTrooper(int $trooperId): self
    {
        return new self('That trooper no longer exists. Reload the page.');
    }

    public static function alreadyPlaced(int $trooperId): self
    {
        return new self('That trooper is already placed in a unit. Reload the page to see where.');
    }

    public static function departed(int $trooperId): self
    {
        return new self('That trooper is no longer on a Cav roster, so they cannot be placed.');
    }

    /**
     * The conditional write found the trooper changed between the checks and the write, e.g. a sync
     * placed them a moment ago.
     */
    public static function changedMeanwhile(int $trooperId): self
    {
        return new self('That trooper was placed or left the roster while this was sent. Reload the page and check.');
    }
}
