<?php

namespace App\Commands\GameIdentities;

use App\Models\GamePlatform;
use DateTimeImmutable;

/**
 * A leader resolves one unmatched player of a captured practice to the trooper they chose
 * (Story 7.7, AD-19): link the identity, then pre-mark that trooper present. `$week` is any moment
 * in the practice's Cav week (AD-11). Handled by {@see LinkUnmatchedPlayerHandler}.
 *
 * `$replace` is the leader's answer to "replace or cancel" (#176): true only once they have seen the
 * different identity the trooper already holds on that game and chose to replace it. Without it a
 * held identity is never overwritten.
 */
final readonly class LinkUnmatchedPlayer
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
        public GamePlatform $platform,
        public string $platformId,
        public int $trooperId,
        public bool $replace = false,
    ) {}
}
