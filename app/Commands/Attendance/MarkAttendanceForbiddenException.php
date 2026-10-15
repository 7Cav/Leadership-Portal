<?php

namespace App\Commands\Attendance;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor's write scope (AD-8, `ScopeResolver::writeScope()`) does not cover the section being
 * marked. Enforced in the handler rather than only by a controller, so a write outside scope is
 * refused whatever the UI allowed. An `AuthorizationException`, so any HTTP caller that lets it
 * propagate answers 403.
 */
class MarkAttendanceForbiddenException extends AuthorizationException
{
    public function __construct(int $sectionId)
    {
        parent::__construct("You may not mark attendance for section {$sectionId}: it is outside your scope.");
    }
}
