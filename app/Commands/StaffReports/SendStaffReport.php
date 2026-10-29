<?php

namespace App\Commands\StaffReports;

use Carbon\CarbonImmutable;

/**
 * A platoon or company HQ staff member sends their own weekly staff report for the Cav week holding `$week` (policy,
 * 2026-10-29): whether they attended a section practice (`$attended`) and, if so, the sections whose practice they
 * attended (`$sectionIds`, one or more).
 */
final readonly class SendStaffReport
{
    /**
     * @param  list<int>  $sectionIds
     */
    public function __construct(
        public int $trooperId,
        public CarbonImmutable $week,
        public bool $attended,
        public array $sectionIds,
    ) {}
}
