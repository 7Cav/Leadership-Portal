<?php

namespace App\Commands\Capture;

use App\Integration\Crcon\Data\CrconPresence;
use Closure;

/**
 * One place a practice's presence can be read from: the `capture_source` value to stamp, the
 * `server_ref` naming the server, and a callable `fn (DateTimeImmutable $start, DateTimeImmutable $end): CrconPresence`
 * that throws {@see CaptureSourceUnavailable} when it cannot answer. `$configured` is false when the
 * source's env keys are unset (AD-23): the run skips it without calling out, because an absent key is
 * "not set up", not an outage (7.9). `$gameServerId` is the stored server it reads (#389), so the read can be
 * recorded against it; null for the legacy env server. `$noServer` is true when the credentials are set but no server
 * id is (an env source without its server id), so the page can say what is actually missing (#631).
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
        public ?int $gameServerId = null,
        public bool $noServer = false,
    ) {}
}
