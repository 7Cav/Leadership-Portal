<?php

namespace App\Repositories;

use App\Models\GamePlatform;
use RuntimeException;
use Throwable;

/**
 * A trooper's in-game identity could not be linked.
 *
 * Translated here, at the layer that owns the database (AD-2), because a CommandHandler may not
 * name `Illuminate\Database\QueryException`. The existing link is always left intact.
 */
class GameIdentityLinkException extends RuntimeException
{
    /** The `(platform, platform_id)` already belongs to another trooper — never silently reassigned. */
    public static function alreadyLinked(GamePlatform $platform, string $platformId, ?Throwable $previous = null): self
    {
        return new self(
            "The {$platform->value} identity {$platformId} is already linked to another trooper.",
            previous: $previous,
        );
    }
}
