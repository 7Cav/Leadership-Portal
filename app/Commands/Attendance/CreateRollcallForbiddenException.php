<?php

namespace App\Commands\Attendance;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor's write scope (AD-8, `ScopeResolver::writeScope()`) does not cover the section whose roll call they tried
 * to create. An `AuthorizationException`, so any HTTP caller that lets it propagate answers 403.
 */
class CreateRollcallForbiddenException extends AuthorizationException
{
    public function __construct(int $sectionId)
    {
        parent::__construct('You may not create the roll call of this section: it is outside your scope.');
    }
}
