<?php

namespace App\Commands\GameIdentities;

use App\Models\GamePlatform;

/**
 * Remove a trooper's identity on one platform. Handled by {@see RemoveTrooperGameIdentityHandler}.
 */
final readonly class RemoveTrooperGameIdentity
{
    public function __construct(
        public int $trooperId,
        public GamePlatform $platform,
    ) {}
}
