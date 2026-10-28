<?php

namespace App\Commands\Guests;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor's write scope (AD-8, `ScopeResolver::writeScope()`) does not cover the host section: a staff grant reads a
 * rollcall but never adds or removes its guests. Enforced in the handlers, whatever the UI allowed. An
 * `AuthorizationException`, so an HTTP caller that lets it propagate answers 403.
 */
class GuestAttendanceForbiddenException extends AuthorizationException
{
    public function __construct(int $sectionId)
    {
        parent::__construct('You may not change the guests of this section: it is outside your scope.');
    }
}
