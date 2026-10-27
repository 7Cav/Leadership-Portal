<?php

namespace App\Commands\Guests;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Attendance\RollcallWeekLockedException;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;

/**
 * A host section's leader adds a guest by hand (decided by the product owner 2026-10-23): "X from another section
 * attended our practice". It is the leader's word standing in for what the game-server capture would have seen, so it
 * is recorded and acted on exactly like a capture sighting, with `source = manual` and the leader as `recorded_by`.
 *
 * Refused before anything is written: a host section outside the actor's write scope (AD-8, 403), a unit that is not a
 * section, a week before `WeekOf::floor()`, a trooper who is unknown, not serving, unplaced or in the host section
 * itself ({@see GuardsHostPractice}), and a trooper already recorded at this practice by either source. Then one audited
 * transaction (`guest.recorded`, AD-16, target the host practice, digest over the new entry): a host week whose report
 * was really sent is refused first, under the report's shared lock (AD-12, `RollcallWeekLockedException`, as every
 * rollcall write); the host practice is found or created and, under its row lock, refused when its leader confirmed it
 * (7.8, mirroring the capture, which never writes into a confirmed practice either); the trooper's standing and
 * placement are read again; the entry is inserted under the UNIQUE key, so two leaders adding the same guest at once
 * land on one row and the second is told it is already listed.
 *
 * Once that has committed, the guest's own section's rollcall gets the automatic makeup through
 * {@see MarkAttendanceHandler::preMarkMakeups()}, the very path a capture sighting takes, with all its guards (same Cav
 * week, never over `present`/`attached`/`loa` or any manual mark, never into a locked, confirmed or before-floor week,
 * skipped for leave and for a trooper no longer serving there). It is audited as `attendance.auto_marked` with the host
 * leader as the actor, on the guest's own practice, so the guest's own leader sees whose word it was and can change it.
 * As with a capture, the entry stands even when the makeup could not be written. The makeup is written only while a
 * sighting of the guest that week still exists (read under a lock before their own practice's), so an Undo that lands
 * between the entry's commit and the makeup leaves nothing behind.
 */
class RecordGuestAttendanceHandler
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
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws GuestAttendanceForbiddenException
     * @throws GuestAttendanceRefusedException
     * @throws RollcallWeekLockedException when the host section's report for the week has been sent.
     */
    public function handle(User $actor, RecordGuestAttendance $command): RecordedGuest
    {
        $weekStart = $this->hostWeek($this->scope, $this->units, $actor, $command->sectionId, $command->week);
        $trooper = $this->refuseNonGuest($this->troopers->find($command->trooperId), $command->sectionId, $this->units);

        // Checked here so a refusal costs no transaction, and again under the locks below. The host practice is created
        // inside the transaction, so a refused guest leaves no empty practice row behind.
        $existing = $this->practices->find($command->sectionId, $weekStart);

        if ($existing?->confirmed_at !== null) {
            throw GuestAttendanceRefusedException::confirmed();
        }

        if ($existing !== null && $this->sightings->find($existing->id, $trooper->id) !== null) {
            throw GuestAttendanceRefusedException::alreadyListed($trooper->display_name);
        }

        $practice = null;
        $sighting = null;
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'guest.recorded',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $command, $weekStart, $trooper, &$practice, &$sighting) {
                if ($this->reports->isSentFor($command->sectionId, $weekStart)) {
                    throw RollcallWeekLockedException::for($command->sectionId, $weekStart);
                }

                $practice = $this->practices->findOrCreate($command->sectionId, $weekStart);

                if ($this->practices->lockById($practice->id)?->confirmed_at !== null) {
                    throw GuestAttendanceRefusedException::confirmed();
                }

                // A roster sync may have moved or departed them since the check above.
                $this->refuseNonGuest($this->troopers->find($trooper->id), $command->sectionId, $this->units);

                $sighting = $this->sightings->recordManual($practice, $trooper->id, $actor->id, $this->acting->adminActingAs($actor->id), $this->acting->billetOf($actor->id))
                    ?? throw GuestAttendanceRefusedException::alreadyListed($trooper->display_name);

                return new AuditedBatch($practice, $sighting);
            },
        );

        $makeups = $this->attendance->preMarkMakeups($practice, [$trooper->id], $actor->id);

        return new RecordedGuest(
            sighting: $sighting,
            practice: $practice,
            makeup: match (true) {
                $makeups->marked > 0 => 'marked',
                $makeups->locked > 0 => 'locked',
                default => 'kept',
            },
        );
    }
}
