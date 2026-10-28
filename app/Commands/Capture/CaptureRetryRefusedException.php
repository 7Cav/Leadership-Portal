<?php

namespace App\Commands\Capture;

use App\Models\Game;
use RuntimeException;

/** A "Try again" that could not even be attempted. The message is written for the leader. */
class CaptureRetryRefusedException extends RuntimeException
{
    public static function noGameLinked(): self
    {
        return new self('No trooper in this section has a game identity linked, so there is no game server to read. Mark attendance by hand.');
    }

    public static function notConfigured(Game $game): self
    {
        return new self("Capture is not set up for {$game->name}. Mark attendance by hand.");
    }

    public static function nothingToRetry(): self
    {
        return new self('There is no failed capture to try again for this week.');
    }
}
