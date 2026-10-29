<?php

namespace App\Commands\Units;

use RuntimeException;

/**
 * Only an Admin sets or clears a unit's oversight (AD-6).
 */
class UnitOversightForbiddenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only an Admin may change who oversees a unit.');
    }
}
