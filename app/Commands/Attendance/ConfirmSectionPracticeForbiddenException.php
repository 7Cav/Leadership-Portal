<?php

namespace App\Commands\Attendance;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor's effective scope (AD-8, via `ScopeResolver`) does not cover the section whose practice
 * they tried to confirm. An `AuthorizationException`, so any HTTP caller that lets it propagate
 * answers 403.
 */
class ConfirmSectionPracticeForbiddenException extends AuthorizationException
{
    public function __construct(int $sectionId)
    {
        parent::__construct("You may not confirm the practice of section {$sectionId}: it is outside your scope.");
    }
}
