<?php

namespace App\Commands\Fireteams;

use App\Authorization\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor does not command the unit whose fireteams, fireteam display or company fireteam policy they tried to set
 * ({@see ScopeResolver::commandsUnit()}: an Admin, or a leader whose synced command billet covers
 * the unit). A staff grant reads a unit but does not make these changes. An `AuthorizationException`, so any HTTP caller
 * that lets it propagate answers 403.
 */
class FireteamForbiddenException extends AuthorizationException
{
    public function __construct(int $unitId)
    {
        parent::__construct('Only the leaders who command this section can change its fireteams.');
    }
}
