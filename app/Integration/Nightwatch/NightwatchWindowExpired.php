<?php

namespace App\Integration\Nightwatch;

use DateTimeImmutable;
use DateTimeZone;
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
        // The portal's date and plain Zulu time (`10OCT26 2359Z`): server text, so no hair space.
        $utc = $windowEnd->setTimezone(new DateTimeZone('UTC'));

        parent::__construct(sprintf(
            'The practice window ended %s, beyond Nightwatch\'s %d-hour match retention.',
            strtoupper($utc->format('dMy')).' '.$utc->format('Hi').'Z',
            $retentionHours,
        ));
    }
}
