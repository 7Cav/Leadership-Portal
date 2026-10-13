<?php

namespace App\Commands\GameIdentities;

use App\Models\GamePlatform;
use DateTimeImmutable;

/**
 * A leader resolves one unmatched player of a captured practice to the trooper they chose
 * (Story 7.7, AD-19): link the identity, then pre-mark that trooper present. `$week` is any moment
 * in the practice's Cav week (AD-11). Handled by {@see LinkUnmatchedPlayerHandler}.
 */
final readonly class LinkUnmatchedPlayer
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
        public GamePlatform $platform,
        public string $platformId,
        public int $trooperId,
    ) {}
}
