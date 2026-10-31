<?php

namespace App\Commands\Practices;

use Illuminate\Auth\Access\AuthorizationException;

/** Only the battalion's HQ staff and Admins may change its practice presence minimum. */
class PresenceMinimumForbiddenException extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct("Only the battalion's HQ staff or an Admin may change its practice presence minimum.");
    }
}
