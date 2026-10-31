<?php

namespace App\Commands\Practices;

use Illuminate\Auth\Access\AuthorizationException;

/** The section is outside the actor's write scope (AD-8), so they may not change its practice slot. */
class PracticeSlotForbiddenException extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct("Only the section's leaders, the command above them or an Admin may change its practice slot.");
    }
}
