<?php

namespace App\Commands\Units;

use App\Authorization\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor does not command the unit whose guidon they tried to save ({@see ScopeResolver::commandsUnit()}: an Admin,
 * or a leader whose command billet or acting lead covers the unit), or it does not exist. A staff grant reads a unit
 * but does not change its guidon. An `AuthorizationException`, so an HTTP caller that lets it propagate answers 403.
 */
class GuidonForbiddenException extends AuthorizationException
{
    public function __construct(int $unitId)
    {
        parent::__construct("You may not change the guidon of unit {$unitId}: only the leaders who command it can.");
    }
}
