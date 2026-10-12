<?php

namespace App\Commands\Capture;

use App\Integration\Crcon\Data\CrconPresence;
use Closure;

/**
 * One place a practice's presence can be read from: the `capture_source` value to stamp, the
 * `server_ref` naming the server, and a callable `fn (DateTimeImmutable $start, DateTimeImmutable $end): CrconPresence`
 * that throws {@see CaptureSourceUnavailable} when it cannot answer. `$configured` is false when the
 * source's env keys are unset (AD-23): the run skips it without calling out, because an absent key is
 * "not set up", not an outage (7.9).
 */
final readonly class CaptureSource
{
    /**
     * @param  Closure(\DateTimeImmutable, \DateTimeImmutable): CrconPresence  $fetch
     */
    public function __construct(
        public string $name,
        public string $serverRef,
        public Closure $fetch,
        public bool $configured = true,
    ) {}
}
