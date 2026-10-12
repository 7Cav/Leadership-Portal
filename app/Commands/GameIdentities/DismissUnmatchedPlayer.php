<?php

namespace App\Commands\GameIdentities;

use App\Models\GamePlatform;
use DateTimeImmutable;

/**
 * A leader says an unmatched player of a captured practice is not a member (Story 7.7, AD-19).
 * Handled by {@see DismissUnmatchedPlayerHandler}.
 */
final readonly class DismissUnmatchedPlayer
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
        public GamePlatform $platform,
        public string $platformId,
    ) {}
}
