<?php

namespace App\Commands\Attendance;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor's effective scope (AD-8, via `ScopeResolver`) does not cover the section whose rollcall
 * they tried to mark posted. An `AuthorizationException`, so any HTTP caller that lets it propagate
 * answers 403.
 */
class MarkRollcallPostedForbiddenException extends AuthorizationException
{
    public function __construct(int $sectionId)
    {
        parent::__construct("You may not mark the rollcall of section {$sectionId} posted: it is outside your scope.");
    }
}
