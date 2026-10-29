<?php

namespace App\Commands\Guests;

use Carbon\CarbonImmutable;

/**
 * The host section's leader says a trooper from another section attended `$sectionId`'s practice in the Cav week
 * holding `$week`: "X from another section attended our practice".
 */
final readonly class RecordGuestAttendance
{
    public function __construct(
        public int $sectionId,
        public CarbonImmutable $week,
        public int $trooperId,
    ) {}
}
