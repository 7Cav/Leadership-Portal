<?php

namespace App\Commands\Guests;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\MakeupWithdrawal;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Attendance\RollcallWeekLockedException;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Undo a hand-added guest (decided by the product owner 2026-10-23). Only an entry a leader added (`source = manual`)
 * can be removed, by anyone who may write the host section (it is the section's record, not one leader's): what the game
 * server saw is evidence no leader can take back. The host gates are the same as adding one ({@see GuardsHostPractice},
 * and inside the transaction a sent host report, AD-12, and a confirmed host practice, 7.8, are refused under their
 * locks).
 *
 * The makeup the entry brought is taken back with it, in the same transaction, only while nothing else supports it:
 * after the entry is deleted, a trooper still recorded at any practice of that Cav week (a capture sighting, or another
 * leader's entry at another section) keeps the makeup, since that sighting would have written it too. Otherwise
 * {@see MarkAttendanceHandler::withdrawMakeup()} clears it if it is still the automatic makeup and the guest's own week
 * may still change, on the practice it was written to even when the guest has moved section since; a leader's mark, `present` from their own capture, and a locked or confirmed own week stay as they
 * are. Since a sighting is only ever deleted here (and with demo history, #115), an automatic makeup with no sighting
 * left that week can only be the one this entry produced.
 *
 * Audited as `guest.removed` (AD-16) on the host practice, digest over the guest's trooper row (the entry itself is
 * gone), and, when the makeup is cleared, `attendance.auto_unmarked` on the guest's own practice with the same actor.
 */
class RemoveGuestAttendanceHandler
{
    use GuardsHostPractice;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly CrossSectionSightingRepository $sightings,
        private readonly WeeklyReportRepository $reports,
        private readonly AuditEnvelope $audit,
        private readonly MarkAttendanceHandler $attendance,
    ) {}

    /**
     * @throws GuestAttendanceForbiddenException
     * @throws GuestAttendanceRefusedException
     * @throws RollcallWeekLockedException when the host section's report for the week has been sent.
     */
    public function handle(User $actor, RemoveGuestAttendance $command): MakeupWithdrawal
    {
        $weekStart = $this->hostWeek($this->scope, $this->units, $actor, $command->sectionId, $command->week);
        $practice = $this->practices->find($command->sectionId, $weekStart);
        $sighting = $practice === null ? null : $this->sightings->find($practice->id, $command->trooperId);
        $trooper = $this->troopers->find($command->trooperId);

        // A staff member's own entry (#448) is not a guest, so there is none here to remove.
        if ($sighting === null || $trooper === null || $sighting->isStaff()) {
            throw GuestAttendanceRefusedException::notListed();
        }

        if (! $sighting->isManual()) {
            throw GuestAttendanceRefusedException::captured($trooper->display_name);
        }

        if ($practice->confirmed_at !== null) {
            throw GuestAttendanceRefusedException::confirmed();
        }

        $makeup = MakeupWithdrawal::Kept;
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'guest.removed',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $command, $weekStart, $practice, $trooper, &$makeup) {
                if ($this->reports->isSentFor($command->sectionId, $weekStart)) {
                    throw RollcallWeekLockedException::for($command->sectionId, $weekStart);
                }

                if ($this->practices->lockById($practice->id)?->confirmed_at !== null) {
                    throw GuestAttendanceRefusedException::confirmed();
                }

                // Another leader removed it meanwhile, or it was never a hand entry.
                if (! $this->sightings->removeManual($practice->id, $trooper->id)) {
                    throw GuestAttendanceRefusedException::notListed();
                }

                $makeup = $this->sightings->existsForTrooperInWeek($trooper->id, $weekStart)
                    ? MakeupWithdrawal::Kept
                    : $this->attendance->withdrawMakeup($trooper->id, $weekStart, $actor->id);

                return new AuditedBatch($practice, $trooper);
            },
        );

        return $makeup;
    }
}
