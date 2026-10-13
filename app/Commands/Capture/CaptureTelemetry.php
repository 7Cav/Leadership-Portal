<?php

namespace App\Commands\Capture;

use App\Models\GamePlatform;
use DateTimeImmutable;

/**
 * Why a practice has no server data, for the rollcall screen (7.9, AD-20): either its game has no
 * source with env keys set (`NotConfigured`, nothing was attempted), or the last capture attempt
 * found no source that could answer (`Failed`, with when). A practice that was never attempted has
 * no state at all, so there is nothing to show.
 */
final readonly class CaptureTelemetry
{
    private function __construct(
        public CaptureTelemetryState $state,
        public ?GamePlatform $platform,
        public ?DateTimeImmutable $lastAttemptAt,
        public bool $canRetry,
    ) {}

    public static function notConfigured(GamePlatform $platform): self
    {
        return new self(CaptureTelemetryState::NotConfigured, $platform, null, false);
    }

    public static function failed(?GamePlatform $platform, DateTimeImmutable $lastAttemptAt): self
    {
        return new self(CaptureTelemetryState::Failed, $platform, $lastAttemptAt, $platform !== null);
    }
}
