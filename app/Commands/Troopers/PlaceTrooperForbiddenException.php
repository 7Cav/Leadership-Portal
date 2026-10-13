<?php

namespace App\Commands\Troopers;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Only an Admin may place a trooper by hand (AD-18, AD-6). Enforced in the handler rather than only
 * by a controller, so a placement is refused whatever the UI offered. An `AuthorizationException`,
 * so any HTTP caller that lets it propagate answers 403.
 */
class PlaceTrooperForbiddenException extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct('Only an Admin may place a trooper into a section.');
    }
}
