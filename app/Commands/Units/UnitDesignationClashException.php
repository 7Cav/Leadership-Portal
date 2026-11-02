<?php

namespace App\Commands\Units;

use RuntimeException;

/**
 * A move would leave two units with one designation under the same parent, or the way picked to settle that cannot
 * apply. Nothing is written. The controller turns it into a form error on `clash`.
 */
class UnitDesignationClashException extends RuntimeException
{
    public static function exists(string $callsign, string $parent): self
    {
        return new self("{$callsign} already exists under {$parent}. Choose how to settle it.");
    }

    public static function gone(string $callsign, string $parent): self
    {
        return new self("Nothing holds {$callsign} under {$parent} any more. Reload the page and try again.");
    }

    public static function outOfScope(string $callsign): self
    {
        return new self("You may not renumber {$callsign}: it is outside your write scope.");
    }

    public static function swapTaken(string $callsign, string $parent): self
    {
        return new self("{$callsign} is taken under {$parent} too, so the two cannot swap. Give one of them another number instead.");
    }

    public static function cannotSwap(string $callsign): self
    {
        return new self("{$callsign} cannot move to the old parent, so the two cannot swap. Give one of them another number instead.");
    }

    public static function ordinalRequired(): self
    {
        return new self('Pick the number or letter it takes instead.');
    }
}
