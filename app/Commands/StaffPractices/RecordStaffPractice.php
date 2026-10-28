<?php

namespace App\Commands\StaffPractices;

use Carbon\CarbonImmutable;

/**
 * A staff member attended `$sectionId`'s practice in the Cav week holding `$week` (#448): entered by them, their staff
 * leader or an Admin, since staff are on no section's rollcall.
 */
final readonly class RecordStaffPractice
{
    public function __construct(
        public int $trooperId,
        public int $sectionId,
        public CarbonImmutable $week,
    ) {}
}
