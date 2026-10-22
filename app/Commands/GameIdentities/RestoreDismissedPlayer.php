<?php

namespace App\Commands\GameIdentities;

use App\Models\GamePlatform;
use DateTimeImmutable;

/**
 * A leader undoes the dismissal of a player a captured practice saw (#176, extends Story 7.7): the
 * player is a member after all, or was dismissed by mistake. `$week` is any moment in the practice's
 * Cav week (AD-11). Handled by {@see RestoreDismissedPlayerHandler}.
 */
final readonly class RestoreDismissedPlayer
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
        public GamePlatform $platform,
        public string $platformId,
    ) {}
}
