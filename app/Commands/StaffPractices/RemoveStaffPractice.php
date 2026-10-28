<?php

namespace App\Commands\StaffPractices;

use Carbon\CarbonImmutable;

/** Take back a staff member's entry for `$sectionId`'s practice in the Cav week holding `$week` (#448). */
final readonly class RemoveStaffPractice
{
    public function __construct(
        public int $trooperId,
        public int $sectionId,
        public CarbonImmutable $week,
    ) {}
}
