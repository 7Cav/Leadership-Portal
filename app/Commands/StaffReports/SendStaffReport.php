<?php

namespace App\Commands\StaffReports;

use Carbon\CarbonImmutable;

/**
 * A platoon, company or battalion HQ staff member sends their own weekly staff report for the Cav week holding `$week`:
 * whether they attended a section practice (`$attended`) and, if so, what kind (`$practiceType`, `sp` when not given)
 * and the sections whose practice they attended (`$sectionIds`: one or more for an SP, optional for an FTX, which
 * names itself with `$ftxName` and `$ftxDate`). `$otherEvents` and `$notes` are optional free text.
 * A battalion HQ staff member may report an office hour instead (`$practiceType` `office_hour`), with its
 * `$officeHourDate` and `$officeHourNote`.
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
        public ?string $practiceType = null,
        public ?string $ftxName = null,
        public ?string $ftxDate = null,
        public ?string $otherEvents = null,
        public ?string $notes = null,
        public ?string $officeHourDate = null,
        public ?string $officeHourNote = null,
    ) {}
}
