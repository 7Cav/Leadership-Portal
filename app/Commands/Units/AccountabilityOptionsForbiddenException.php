<?php

namespace App\Commands\Units;

use Illuminate\Auth\Access\AuthorizationException;

/** A battalion's accountability options are an Admin's or Regimental HQ staff's; those under it also its battalion HQ staff's. */
class AccountabilityOptionsForbiddenException extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct("Only an Admin or Regimental HQ staff may change a battalion's accountability options, and only they or the battalion's HQ staff those of a unit under it.");
    }
}
