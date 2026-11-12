<?php

namespace App\Commands\Units;

use RuntimeException;

/**
 * A deactivate or reactivate that cannot apply to this unit. The controller turns it into a form error on `active`.
 */
class InvalidUnitActivationException extends RuntimeException
{
    public static function regiment(): self
    {
        return new self('The regiment cannot be deactivated.');
    }

    public static function alreadyInactive(): self
    {
        return new self('This unit is already inactive.');
    }

    public static function notDeactivated(): self
    {
        return new self('This unit is not deactivated. Reactivate the inactive unit above it instead.');
    }
}
