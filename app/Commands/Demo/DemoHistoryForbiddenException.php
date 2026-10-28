<?php

namespace App\Commands\Demo;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Demo history (#115) is an Admin tool: refused for a non-Admin actor, and for a unit outside the
 * actor's scope (AD-8) or that does not exist. An `AuthorizationException`, so an HTTP caller that
 * lets it propagate answers 403.
 */
class DemoHistoryForbiddenException extends AuthorizationException
{
    public static function notAdmin(): self
    {
        return new self('Only an Admin may generate or purge demo history.');
    }

    public static function outOfScope(int $unitId): self
    {
        return new self('That unit is outside your scope, or no longer exists.');
    }
}
