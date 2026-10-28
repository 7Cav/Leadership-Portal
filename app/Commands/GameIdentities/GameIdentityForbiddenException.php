<?php

namespace App\Commands\GameIdentities;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The trooper is outside the actor's write scope (AD-8) — or is not a placed, active trooper
 * at all. The two are one answer on purpose, so trooper ids are not probeable beyond a leader's
 * scope. Also raised when the section of a practice being reviewed is outside it (Story 7.7). An
 * `AuthorizationException`, like every other `*ForbiddenException`, so an HTTP caller that lets it
 * propagate answers 403.
 */
class GameIdentityForbiddenException extends AuthorizationException
{
    public static function forTrooper(int $trooperId): self
    {
        return new self('That trooper is outside your scope.');
    }

    public static function forSection(int $sectionId): self
    {
        return new self('That section is outside your scope.');
    }
}
