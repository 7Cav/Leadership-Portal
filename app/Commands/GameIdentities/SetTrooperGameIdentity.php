<?php

namespace App\Commands\GameIdentities;

use App\Models\GamePlatform;

/**
 * Link a trooper to a player on one platform, or correct the identity they already have there
 * (a rename keeps the same row). Handled by {@see SetTrooperGameIdentityHandler}.
 */
final readonly class SetTrooperGameIdentity
{
    public string $playerName;

    public string $platformId;

    public function __construct(
        public int $trooperId,
        public GamePlatform $platform,
        string $playerName,
        string $platformId,
    ) {
        $this->playerName = trim($playerName);
        $this->platformId = trim($platformId);
    }
}
