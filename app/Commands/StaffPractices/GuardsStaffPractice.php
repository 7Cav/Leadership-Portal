<?php

namespace App\Commands\StaffPractices;

use App\Authorization\StaffPracticeAccess;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * The gates both staff practice Commands share (#448): the host must be a section (any section of the regiment, not
 * only one in the actor's scope: staff attend practices across the regiment), and the week must be neither before
 * `WeekOf::floor()` nor in the future. The lock is the staff member's own unit's in that week: a week whose report the
 * unit they were placed in then sent is frozen, as a section's is (AD-12). Only an HQ node sends a weekly report; a staff department has none, so its weeks
 * stay open.
 */
trait GuardsStaffPractice
{
    /** @throws StaffPracticeRefusedException */
    private function practiceWeek(UnitRepository $units, int $sectionId, CarbonImmutable $week): CarbonImmutable
    {
        if ($units->find($sectionId)?->type !== UnitType::Section) {
            throw StaffPracticeRefusedException::notASection();
        }

        $weekStart = WeekOf::start($week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw StaffPracticeRefusedException::weekBeforeFloor($weekStart);
        }

        if ($weekStart->greaterThan(WeekOf::start(CarbonImmutable::now()))) {
            throw StaffPracticeRefusedException::future();
        }

        return $weekStart;
    }

    /**
     * Refused once a unit the trooper was placed in during that week sent its report for it (audit L3): the unit whose
     * report froze the week, not the one they are in now, so a move afterwards neither opens a frozen week nor freezes
     * an open one. A week spent in two units (a move mid-week) is locked by either. Called inside the audited
     * transaction, where `isSentFor()` holds each report row's shared lock, so a send and this write never interleave.
     *
     * @throws StaffPracticeRefusedException
     */
    private function refuseLockedWeek(WeeklyReportRepository $reports, TrooperPlacementRepository $placements, Trooper $trooper, CarbonImmutable $weekStart): void
    {
        foreach ($placements->unitIdsOfTrooperInWeek($trooper, $weekStart) as $unitId) {
            if ($reports->isSentFor($unitId, $weekStart)) {
                throw StaffPracticeRefusedException::locked($weekStart);
            }
        }
    }

    /**
     * The trooper as a staff member who may have an entry now: serving, and placed on an HQ node or a staff department.
     *
     * @throws StaffPracticeRefusedException
     */
    private function refuseNonStaff(StaffPracticeAccess $access, ?Trooper $trooper): Trooper
    {
        return match (true) {
            $trooper === null => throw StaffPracticeRefusedException::unknownTrooper(),
            $access->staffUnit($trooper) === null => throw StaffPracticeRefusedException::notStaff($trooper->display_name),
            ! $trooper->status->isServing() => throw StaffPracticeRefusedException::notServing($trooper->display_name),
            default => $trooper,
        };
    }
}
