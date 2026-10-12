<?php

namespace App\Integration\Crcon\Data;

use App\Integration\Crcon\CrconUnavailable;
use DateTimeImmutable;

/**
 * Who CRCON saw on the server during a window, deduplicated by platform id and stamped with the
 * time of the fetch (AD-20). Only ever built from a *successful* read: an empty `players` list
 * means the window was genuinely empty, whereas a failed read raises
 * {@see CrconUnavailable} and never produces one of these.
 */
readonly class CrconPresence
{
    /**
     * @param  list<CrconPlayer>  $players
     */
    public function __construct(
        public array $players,
        public DateTimeImmutable $windowStart,
        public DateTimeImmutable $windowEnd,
        public DateTimeImmutable $fetchedAt,
    ) {}
}
