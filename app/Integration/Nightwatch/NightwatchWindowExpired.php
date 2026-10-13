<?php

namespace App\Integration\Nightwatch;

use DateTimeImmutable;
use RuntimeException;

/**
 * The practice window ended before Nightwatch's retention reaches back to, so its data is gone
 * and the answer is unknowable. Distinct from {@see NightwatchUnavailable} (a retry may help
 * there; here it never will) and never an empty list: "nobody came" and "too late to ask" must
 * not look alike to the capture run.
 */
class NightwatchWindowExpired extends RuntimeException
{
    public function __construct(
        public readonly DateTimeImmutable $windowEnd,
        public readonly int $retentionHours,
    ) {
        parent::__construct(sprintf(
            'The practice window ended %s, beyond Nightwatch\'s %d-hour match retention.',
            $windowEnd->format('Y-m-d H:i \U\T\C'),
            $retentionHours,
        ));
    }
}
