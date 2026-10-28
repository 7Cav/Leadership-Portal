<?php

namespace App\Commands\Capture;

use RuntimeException;

/** The practice's capture was requested a moment ago; `$retryAfter` is the seconds until it may be again. */
class CaptureRetryThrottledException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("The game server was tried a moment ago. Try again in {$retryAfter} seconds.");
    }
}
