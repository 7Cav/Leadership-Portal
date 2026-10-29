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
 * A platoon, company or battalion HQ staff member sends their own weekly staff report: whether they attended
 * at least one section practice that Cav week, what kind (a section practice, an FTX or, for battalion HQ staff, an
 * office hour), which, the other events they
 * attended and their notes. Only they send it, signed in as themselves
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
 * Refused before anything is written: an unknown trooper, anyone but the staff member (403), a week before the floor,
 * in the future or still running (a week is sent once it has ended), a trooper not on a platoon, company or battalion HQ when the week ended (by the placement history, so a past week
 * goes by who was on the HQ then) or no longer serving, "Yes" to an SP with nothing picked or "No" with picks or an FTX,
 * a practice type other than SP or FTX (or, on a battalion HQ, an office hour), an FTX without a name or with a date
 * outside the week, an office hour without a note or with a date outside the week or with sections picked, text over its limit, a
 * pick that is not a section, and a report already sent. Then one audited transaction (`staff_report.sent`, AD-16, target the report,
 * digest over it and the week's entries): the report row is inserted under its UNIQUE key, so two sends at once land on
 * one and the second is told it was already sent. The HQ's own weekly report does not lock this: the staff member's
 * week is theirs to report until they send it.
 */
class SendStaffReportHandler
{
    /** The most practices one report takes: far more than a week holds, a bound on the request only. */
    public const MAX_PRACTICES = 20;

    public const MAX_FTX_NAME = 120;

    public const MAX_OTHER_EVENTS = 500;

    public const MAX_NOTES = 1000;

    public const MAX_OFFICE_HOUR_NOTE = 200;

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

        // A week is reported once it is over, so the HQ it goes to is the one they ended it on.
        if (! WeekOf::hasEnded($weekStart, CarbonImmutable::now())) {
            throw StaffReportRefusedException::weekNotEnded($weekStart);
        }

        $hq = $this->access->owes($trooper, $weekStart) ?? throw StaffReportRefusedException::notOwed($trooper->display_name, $weekStart);
        $fields = $this->fields($command, $weekStart, $hq);
        $sections = $this->pickedSections($command, $fields['practice_type']);

        if ($this->reports->find($trooper->id, $weekStart) !== null) {
            throw StaffReportRefusedException::alreadySent($weekStart);
        }

        $report = null;
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'staff_report.sent',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $trooper, $weekStart, $hq, $sections, $fields, $command, &$report) {
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
                    $fields,
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
     * The rest of what was sent, checked and trimmed: the practice type (SP unless FTX or office hour is picked, null
     * with "No"), the FTX's name and date (only for an FTX, both required, the date inside the Cav week), the office
     * hour's date and note (only for an office hour, which only battalion HQ staff report: an hour of office hours in
     * place of the hour of play, self-reported and unverified), other events and notes (empty text is null).
     *
     * @return array{practice_type: ?string, ftx_name: ?string, ftx_date: ?string, office_hour_date: ?string, office_hour_note: ?string, other_events: ?string, notes: ?string}
     *
     * @throws StaffReportRefusedException
     */
    private function fields(SendStaffReport $command, CarbonImmutable $weekStart, Unit $hq): array
    {
        $type = $command->practiceType === null || $command->practiceType === '' ? null : $command->practiceType;
        $ftxName = self::text($command->ftxName);
        $ftxDate = self::text($command->ftxDate);
        $officeDate = self::text($command->officeHourDate);
        $officeNote = self::text($command->officeHourNote);

        if ($type !== null && ! in_array($type, [StaffReport::SP, StaffReport::FTX, StaffReport::OFFICE_HOUR], true)) {
            throw StaffReportRefusedException::unknownPracticeType();
        }

        if ($type === StaffReport::OFFICE_HOUR && $hq->type !== UnitType::Battalion) {
            throw StaffReportRefusedException::officeHourNotBattalion();
        }

        if (! $command->attended) {
            if ($type === StaffReport::FTX || $ftxName !== null || $ftxDate !== null) {
                throw StaffReportRefusedException::ftxWithNo();
            }
            if ($type === StaffReport::OFFICE_HOUR || $officeDate !== null || $officeNote !== null) {
                throw StaffReportRefusedException::officeHourFieldsElsewhere();
            }
            $type = null;
        } else {
            $type ??= StaffReport::SP;
        }

        if ($type === StaffReport::FTX) {
            if ($ftxName === null) {
                throw StaffReportRefusedException::ftxNameMissing();
            }
            if (mb_strlen($ftxName) > self::MAX_FTX_NAME) {
                throw StaffReportRefusedException::tooLong('ftx_name', 'the FTX name', self::MAX_FTX_NAME);
            }

            if (! self::inWeek($ftxDate, $weekStart)) {
                throw StaffReportRefusedException::ftxDateOutsideWeek($weekStart);
            }
        } elseif ($ftxName !== null || $ftxDate !== null) {
            // An SP has no FTX name or date: the form clears them, so these came from somewhere else.
            throw StaffReportRefusedException::ftxFieldsWithSp();
        }

        if ($type === StaffReport::OFFICE_HOUR) {
            if (! self::inWeek($officeDate, $weekStart)) {
                throw StaffReportRefusedException::officeHourDateOutsideWeek($weekStart);
            }
            if ($officeNote === null) {
                throw StaffReportRefusedException::officeHourNoteMissing();
            }
            if (mb_strlen($officeNote) > self::MAX_OFFICE_HOUR_NOTE) {
                throw StaffReportRefusedException::tooLong('office_hour_note', 'the office hour note', self::MAX_OFFICE_HOUR_NOTE);
            }
        } elseif ($officeDate !== null || $officeNote !== null) {
            throw StaffReportRefusedException::officeHourFieldsElsewhere();
        }

        $otherEvents = self::text($command->otherEvents);
        $notes = self::text($command->notes);

        if ($otherEvents !== null && mb_strlen($otherEvents) > self::MAX_OTHER_EVENTS) {
            throw StaffReportRefusedException::tooLong('other_events', 'the other events', self::MAX_OTHER_EVENTS);
        }
        if ($notes !== null && mb_strlen($notes) > self::MAX_NOTES) {
            throw StaffReportRefusedException::tooLong('notes', 'the notes', self::MAX_NOTES);
        }

        return [
            'practice_type' => $type,
            'ftx_name' => $type === StaffReport::FTX ? $ftxName : null,
            'ftx_date' => $type === StaffReport::FTX ? $ftxDate : null,
            'office_hour_date' => $type === StaffReport::OFFICE_HOUR ? $officeDate : null,
            'office_hour_note' => $type === StaffReport::OFFICE_HOUR ? $officeNote : null,
            'other_events' => $otherEvents,
            'notes' => $notes,
        ];
    }

    /** Whether `$date` is a real `YYYY-MM-DD` inside the Cav week opening on `$weekStart`. */
    private static function inWeek(?string $date, CarbonImmutable $weekStart): bool
    {
        $parsed = $date === null ? false : CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        return $parsed !== false && $parsed->toDateString() === $date && WeekOf::start($parsed)->equalTo($weekStart);
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * The sections picked, each once and in the order given, checked against the answer.
     *
     * @return list<Unit>
     *
     * @throws StaffReportRefusedException
     */
    private function pickedSections(SendStaffReport $command, ?string $type): array
    {
        $ids = array_values(array_unique(array_map('intval', $command->sectionIds)));

        // An FTX may span several sections or none in particular: its name and date say what it was.
        if ($command->attended && $type === StaffReport::SP && $ids === []) {
            throw StaffReportRefusedException::noPractice();
        }

        if ($command->attended && $type === StaffReport::OFFICE_HOUR && $ids !== []) {
            throw StaffReportRefusedException::officeHourWithSections();
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
