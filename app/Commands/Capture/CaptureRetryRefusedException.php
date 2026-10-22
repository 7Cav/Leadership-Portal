<?php

namespace App\Commands\Capture;

use App\Models\GamePlatform;
use RuntimeException;

/** A "Try again" that could not even be attempted. The message is written for the leader. */
class CaptureRetryRefusedException extends RuntimeException
{
    public static function noGameLinked(): self
    {
        return new self('No trooper in this section has a game identity linked, so there is no server to read. Mark attendance by hand.');
    }

    public static function notConfigured(GamePlatform $platform): self
    {
        return new self("Telemetry is not configured for {$platform->label()}. Mark attendance by hand.");
    }

    public static function nothingToRetry(): self
    {
        return new self('There is no failed capture to try again for this week.');
    }
}
