<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;

/**
 * One guest's visits to the practices of a unit's sections over a span of Cav weeks, as
 * {@see CrossSectionSightingRepository::visitsWithin()} counts them: `visits` is the number of host practices they were
 * recorded at (one per practice, whatever the source), `lastWeek` the newest of those practices' weeks (AD-11).
 */
final readonly class GuestVisits
{
    public function __construct(
        public int $trooperId,
        public int $visits,
        public CarbonImmutable $lastWeek,
    ) {}
}
