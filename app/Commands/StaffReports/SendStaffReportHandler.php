<?php

namespace App\Commands\StaffReports;

use App\Authorization\StaffReportAccess;
use App\Models\CrossSectionSighting;
use App\Models\StaffReport;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\StaffReportRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * A platoon or company HQ staff member sends their own weekly staff report: whether they attended
 * at least one section practice that Cav week, and which. Only they send it, signed in as themselves
 * ({@see StaffReportAccess::maySend()}); nobody fills it in for them, Admins included. A sent report is final, as a
 * unit's weekly report is: there is no draft, no edit and no second send.
 *
 * The practices picked are stored as the staff member's staff practice entries (`cross_section_sightings`, source
 * `staff`), the same store the milpac uses, so the host section's rollcall lists them and the milpac shows them. The
 * report sets the week's entries to exactly what was picked: an entry already there for a picked section stays, a
 * missing one is added, and an entry for a section not picked (one a leader entered on the milpac, say) is removed, so
 * the record says what the staff member said. Each removed entry gets its own `staff_practice.removed` audit row in
 * the same transaction, naming the entry and who had entered it. A guest row from before they joined the HQ is left as it is and still
 * counts as a practice they attended. Nothing is written on the host's rollcall, numbers, reports or makeups.
 *
 * Refused before anything is written: an unknown trooper, anyone but the staff member (403), a week before the floor or
 * in the future, a trooper not on a platoon or company HQ when the week ended (by the placement history, so a past week
 * goes by who was on the HQ then) or no longer serving, "Yes" with nothing picked or "No" with picks, a pick that is not
 * a section, and a report already sent. Then one audited transaction (`staff_report.sent`, AD-16, target the report,
 * digest over it and the week's entries): the report row is inserted under its UNIQUE key, so two sends at once land on
 * one and the second is told it was already sent. The HQ's own weekly report does not lock this: the staff member's
 * week is theirs to report until they send it.
 */
class SendStaffReportHandler
{
    /** The most practices one report takes: far more than a week holds, a bound on the request only. */
    public const MAX_PRACTICES = 20;

    public function __construct(
        private readonly StaffReportAccess $access,
        private readonly TrooperRepository $troopers,
        private readonly UnitRepository $units,
        private readonly SectionPracticeRepository $practices,
        private readonly CrossSectionSightingRepository $sightings,
        private readonly StaffReportRepository $reports,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws StaffReportForbiddenException
     * @throws StaffReportRefusedException
     */
    public function handle(User $actor, SendStaffReport $command): StaffReport
    {
        $trooper = $this->troopers->find($command->trooperId) ?? throw StaffReportRefusedException::unknownTrooper();

        // Who first, so nobody else learns anything more about the trooper's week (AD-8).
        if (! $this->access->maySend($actor, $trooper)) {
            throw new StaffReportForbiddenException;
        }

        $weekStart = WeekOf::start($command->week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw StaffReportRefusedException::weekBeforeFloor($weekStart);
        }

        if ($weekStart->greaterThan(WeekOf::start(CarbonImmutable::now()))) {
            throw StaffReportRefusedException::future();
        }

        $hq = $this->access->owes($trooper, $weekStart) ?? throw StaffReportRefusedException::notOwed($trooper->display_name, $weekStart);
        $sections = $this->pickedSections($command);

        if ($this->reports->find($trooper->id, $weekStart) !== null) {
            throw StaffReportRefusedException::alreadySent($weekStart);
        }

        $report = null;
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'staff_report.sent',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $trooper, $weekStart, $hq, $sections, $command, &$report) {
                if ($this->reports->isSent($trooper->id, $weekStart)) {
                    throw StaffReportRefusedException::alreadySent($weekStart);
                }

                $entries = [];
                $picked = [];

                foreach ($sections as $section) {
                    // A demo week stays demo (as a staff practice entry keeps it): the entry is the staff member's.
                    $practice = $this->practices->findOrCreate($section->id, $weekStart, keepDemo: true);
                    $held = $this->sightings->find($practice->id, $trooper->id);
                    $entries[] = $held ?? $this->sightings->recordStaff($practice, $trooper->id, $actor->id)
                        ?? $this->sightings->find($practice->id, $trooper->id);
                    $picked[$practice->id] = true;
                }

                // An entry for a section not picked is not what they reported: take it off, and its practice with it
                // when nothing else is on it (as removing a staff practice does).
                foreach ($this->sightings->staffEntriesInWeek($trooper->id, $weekStart) as $entry) {
                    if (! isset($picked[$entry->section_practice_id])) {
                        $this->recordRemoved($actor, $trooper->id, $entry);
                        $this->practices->deleteIfUnused($entry->practice);
                    }
                }

                $report = $this->reports->send(
                    $trooper->id,
                    $hq->id,
                    $weekStart,
                    $command->attended,
                    array_map(fn (Unit $section) => ['section_id' => $section->id, 'section' => $section->callsign ?? $section->name], $sections),
                    $actor->id,
                ) ?? throw StaffReportRefusedException::alreadySent($weekStart);

                return new AuditedBatch($report, ...array_values(array_filter($entries, fn (?CrossSectionSighting $row) => $row !== null)));
            },
        );

        return $report;
    }

    /**
     * Take off one staff practice entry the report did not pick, audited on its own (`staff_practice.removed`, target
     * the entry, digest over it as it stood) inside the send's transaction: the send's own row digests only what is left,
     * so this one keeps the record of the entry, its practice and who had entered it.
     */
    private function recordRemoved(User $actor, int $trooperId, CrossSectionSighting $entry): void
    {
        $section = $entry->practice->section;
        $label = $section === null ? 'a section' : ($section->callsign ?? $section->name);
        $by = $entry->recordedBy?->username;

        $this->audit->record(
            actorId: $actor->id,
            action: 'staff_practice.removed',
            reason: "Not picked in the staff report: the practice of {$label}".($by === null ? '' : ", entered by {$by}").'.',
            reasonRequired: false,
            mutation: function () use ($trooperId, $entry) {
                $this->sightings->removeStaff($entry->section_practice_id, $trooperId);

                return $entry->withoutRelations();
            },
        );
    }

    /**
     * The sections picked, each once and in the order given, checked against the answer.
     *
     * @return list<Unit>
     *
     * @throws StaffReportRefusedException
     */
    private function pickedSections(SendStaffReport $command): array
    {
        $ids = array_values(array_unique(array_map('intval', $command->sectionIds)));

        if ($command->attended && $ids === []) {
            throw StaffReportRefusedException::noPractice();
        }

        if (! $command->attended && $ids !== []) {
            throw StaffReportRefusedException::practicesWithNo();
        }

        return array_map(function (int $id) {
            $unit = $this->units->find($id);

            return $unit !== null && $unit->type === UnitType::Section ? $unit : throw StaffReportRefusedException::notASection();
        }, $ids);
    }
}
