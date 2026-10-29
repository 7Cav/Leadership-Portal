<?php

namespace App\Commands\StaffPractices;

use App\Authorization\StaffPracticeAccess;
use App\Models\CrossSectionSighting;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * A staff member attended a section's practice (#448): HQ staff and staff departments are on no section's rollcall, so
 * they, their staff leader or an Admin enter it here ({@see StaffPracticeAccess}). Stored as a `cross_section_sightings`
 * row with source `staff` and the entering user as `recorded_by` (with the Admin acting as them, or the billet held, like
 * every other who-column), so a self-report reads as one.
 *
 * It is the staff member's record only: nothing is written on the host section's rollcall, nothing counts in its numbers
 * or reports, and no makeup is written anywhere (staff have no section of their own to make up). The host's sent report
 * or confirmed practice does not lock it, since nothing of the host's changes.
 *
 * Refused before anything is written: an unknown trooper, an actor who may not speak for them (403), a trooper who is not
 * staff or no longer serving, a unit that is not a section, a week before `WeekOf::floor()`, more than {@see WEEKS} weeks
 * back or in the future, and a trooper already recorded at that practice by any source (a guest entry from before they
 * moved to the staff is named as one, since it shows on the section's rollcall, not here). Then one audited transaction (`staff_practice.recorded`,
 * AD-16, target the trooper, digest over the new entry): a week whose report the unit the trooper was in that week sent is refused under
 * the report's lock ({@see GuardsStaffPractice}); the trooper is read again (a roster sync may have moved them); the host
 * practice is found or created (a generated demo practice stays demo, so the demo purge still clears its marks); the entry
 * is inserted under the UNIQUE key, so two people entering it at once land on one row and the second is told it is
 * already recorded.
 */
class RecordStaffPracticeHandler
{
    use GuardsStaffPractice;

    /**
     * How many Cav weeks back an entry may be for, the current one included (audit 2, L3): the milpac form offers the
     * same weeks, never before the floor. Taking an entry back is not bound by it, only by the floor.
     */
    public const WEEKS = 12;

    public function __construct(
        private readonly StaffPracticeAccess $access,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly CrossSectionSightingRepository $sightings,
        private readonly WeeklyReportRepository $reports,
        private readonly TrooperPlacementRepository $placements,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws StaffPracticeForbiddenException
     * @throws StaffPracticeRefusedException
     */
    public function handle(User $actor, RecordStaffPractice $command): CrossSectionSighting
    {
        $trooper = $this->troopers->find($command->trooperId) ?? throw StaffPracticeRefusedException::unknownTrooper();

        // Who first, so someone who may not speak for the trooper learns nothing more about them (AD-8).
        if (! $this->access->mayRemove($actor, $trooper)) {
            throw new StaffPracticeForbiddenException;
        }

        $trooper = $this->refuseNonStaff($this->access, $trooper);

        if (! $this->access->mayRecord($actor, $trooper)) {
            throw new StaffPracticeForbiddenException;
        }

        $weekStart = $this->practiceWeek($this->units, $command->sectionId, $command->week);

        if ($weekStart->lessThan(WeekOf::start(CarbonImmutable::now())->subWeeks(self::WEEKS - 1))) {
            throw StaffPracticeRefusedException::outsideWindow($weekStart, self::WEEKS);
        }

        // Checked here so a refusal costs no transaction, and again under the UNIQUE key below. The host practice is
        // created inside the transaction, so a refused entry leaves no empty practice row behind.
        $existing = $this->practices->find($command->sectionId, $weekStart);

        $held = $existing === null ? null : $this->sightings->find($existing->id, $trooper->id);

        if ($held !== null) {
            throw $this->alreadyHeld($held, $trooper->display_name);
        }

        $sighting = null;
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'staff_practice.recorded',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $command, $weekStart, $trooper, &$sighting) {
                $this->refuseLockedWeek($this->reports, $this->placements, $trooper, $weekStart);

                $current = $this->refuseNonStaff($this->access, $this->troopers->find($trooper->id));
                // A demo week stays demo (audit 2, L1): the entry is the staff member's, nothing of the host's.
                $practice = $this->practices->findOrCreate($command->sectionId, $weekStart, keepDemo: true);

                $sighting = $this->sightings->recordStaff($practice, $current->id, $actor->id, $this->acting->adminActingAs($actor->id), $this->acting->billetOf($actor->id))
                    ?? throw $this->alreadyHeld($this->sightings->find($practice->id, $current->id), $current->display_name);

                return new AuditedBatch($current, $sighting);
            },
        );

        return $sighting;
    }

    /**
     * The refusal for a practice that already holds a row for the trooper, naming what holds it (audit L2): a staff
     * entry, or a guest entry from before they moved to the staff, which shows on the section's rollcall instead.
     */
    private function alreadyHeld(?CrossSectionSighting $held, string $name): StaffPracticeRefusedException
    {
        return $held === null || $held->isStaff()
            ? StaffPracticeRefusedException::alreadyRecorded($name)
            : StaffPracticeRefusedException::alreadyAGuest($name, ! $held->isManual());
    }
}
