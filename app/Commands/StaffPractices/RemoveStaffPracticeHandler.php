<?php

namespace App\Commands\StaffPractices;

use App\Authorization\StaffPracticeAccess;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Take back a staff member's practice entry (#448). The same people who may enter one may remove it
 * ({@see StaffPracticeAccess::mayRemove()}, judged on where the trooper is placed now), and only an entry with source
 * `staff`: a guest a host leader added, or what the game server saw, is never removed here. The week gates are the same
 * as entering one ({@see GuardsStaffPractice}). Nothing else changes with it, since an entry never touched a rollcall.
 *
 * Audited as `staff_practice.removed` (AD-16), target the trooper, digest over the trooper and the host practice (the
 * entry itself is gone).
 */
class RemoveStaffPracticeHandler
{
    use GuardsStaffPractice;

    public function __construct(
        private readonly StaffPracticeAccess $access,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly CrossSectionSightingRepository $sightings,
        private readonly WeeklyReportRepository $reports,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws StaffPracticeForbiddenException
     * @throws StaffPracticeRefusedException
     */
    public function handle(User $actor, RemoveStaffPractice $command): void
    {
        $trooper = $this->troopers->find($command->trooperId) ?? throw StaffPracticeRefusedException::unknownTrooper();

        if (! $this->access->mayRemove($actor, $trooper)) {
            throw new StaffPracticeForbiddenException;
        }

        $weekStart = $this->practiceWeek($this->units, $command->sectionId, $command->week);
        $practice = $this->practices->find($command->sectionId, $weekStart);

        if ($practice === null || $this->sightings->find($practice->id, $trooper->id)?->isStaff() !== true) {
            throw StaffPracticeRefusedException::notRecorded();
        }

        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'staff_practice.removed',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($trooper, $weekStart, $practice) {
                $this->refuseLockedWeek($this->reports, $trooper, $weekStart);

                // Someone else removed it meanwhile.
                if (! $this->sightings->removeStaff($practice->id, $trooper->id)) {
                    throw StaffPracticeRefusedException::notRecorded();
                }

                return new AuditedBatch($trooper, $practice);
            },
        );
    }
}
