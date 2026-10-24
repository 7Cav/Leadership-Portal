<?php

namespace App\Commands\Units;

use RuntimeException;

/**
 * Only an Admin assigns or removes an acting lead (AD-6).
 */
class ActingLeadForbiddenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only an Admin may assign or remove an acting lead.');
    }
}
