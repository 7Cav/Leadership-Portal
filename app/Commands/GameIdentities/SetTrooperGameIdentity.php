<?php

namespace App\Commands\GameIdentities;

use App\Models\GamePlatform;

/**
 * Link a trooper to a player on one platform, or correct the identity they already have there
 * (a rename keeps the same row). Handled by {@see SetTrooperGameIdentityHandler}.
 *
 * `$replacing` makes the write conditional (#176): when set, it goes ahead only if the trooper's
 * identity on that platform, read under lock in the write's own transaction, is none, already
 * `$platformId`, or exactly `$replacing` — the platform id the leader was shown when they chose to
 * replace it. Anything else means it changed since they were asked, and nothing is written. Null
 * (the Game identities screen, where the leader edits the row they see) writes unconditionally.
 */
final readonly class SetTrooperGameIdentity
{
    public string $playerName;

    public string $platformId;

    public ?string $replacing;

    public function __construct(
        public int $trooperId,
        public GamePlatform $platform,
        string $playerName,
        string $platformId,
        ?string $replacing = null,
    ) {
        $this->playerName = trim($playerName);
        $this->platformId = trim($platformId);
        $this->replacing = $replacing === null ? null : trim($replacing);
    }
}
