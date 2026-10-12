<?php

namespace App\Commands\GameIdentities;

use RuntimeException;

/**
 * The trooper is outside the actor's effective scope (AD-8) — or is not a placed, active trooper
 * at all. The two are one answer on purpose, so trooper ids are not probeable beyond a leader's
 * scope.
 */
class GameIdentityForbiddenException extends RuntimeException
{
    public static function forTrooper(int $trooperId): self
    {
        return new self("Trooper {$trooperId} is outside your scope.");
    }
}
