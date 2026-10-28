<?php

namespace App\Commands\Units;

use InvalidArgumentException;

/**
 * A guidon that cannot be saved: the unit is not a company, battalion or the regiment, or a color or text breaks
 * {@see SaveUnitGuidonHandler}'s rules. Checked before anything is written.
 */
class InvalidGuidonException extends InvalidArgumentException
{
    public static function notAGuidonUnit(): self
    {
        return new self('Only a company, a battalion or the regiment has a guidon.');
    }

    public static function badField(string $field): self
    {
        return new self("The guidon's {$field} is not allowed.");
    }
}
