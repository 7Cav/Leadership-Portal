<?php

namespace App\Commands\StaffReports;

use App\Authorization\StaffReportAccess;
use App\Models\CrossSectionSighting;
use App\Models\StaffReport;
use App\Models\TelemetryInterface;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\GameRepository;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\ReportEditWindow;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\StaffReportRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitGame;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * A platoon, company or battalion HQ staff member sends their own weekly staff report: their accountability that Cav
 * week (platoon and company HQ staff: a section practice in their own unit or an FTX; battalion HQ staff: a section
 * practice in the battalion, an operation, a class or another official event), their presence (an hour on the public
 * server, an office hour, or neither), the other events they attended and their notes. Only they send it, signed in as themselves
 * ({@see StaffReportAccess::maySend()}); nobody fills it in for them, Admins included. There is no draft. Until it locks
 * at its deadline ({@see ReportEditWindow}, as a unit's weekly report does) they may send it again with a reason: the
 * row is rewritten in place, the version it replaces kept in `revisions`, and the week's entries set again as below,
 * audited as `staff_report.edited` (reason required). After the deadline a sent report is final.
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
 * a practice type their HQ does not report (SP or FTX on a platoon or company HQ; SP, operation, class or event on a
 * battalion HQ), no presence answer or one other than public server, office hour or none, a named type without a name or with a date
 * outside the week, an office hour without a note or with a date outside the week, text over its limit, a
 * pick that is not a section or not under their HQ, a report already sent and past its deadline, and an edit with no reason. Then one audited transaction (`staff_report.sent`, AD-16, target the report,
 * digest over it and the week's entries): the report row is inserted under its UNIQUE key, so two sends at once land on
 * one and the second is told it was already sent. The game server check ({@see presenceCheck()}) and the public server
 * check ({@see PublicServerHour}) are computed before the transaction and frozen on the row. The HQ's own weekly report does not lock this: the staff member's
 * week is theirs to report until they send it.
 */
class SendStaffReportHandler
{
    /** The most practices one report takes: far more than a week holds, a bound on the request only. */
    public const MAX_PRACTICES = 20;

    public const SENT = 'staff_report.sent';

    public const EDITED = 'staff_report.edited';

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
        private readonly PracticeCapturePlayerRepository $captures,
        private readonly GameRepository $games,
        private readonly PublicServerHour $publicServer,
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

        // A running week may be sent before it ends, and edited until its deadline; it goes to the HQ they are on now.
        $hq = $this->access->owes($trooper, $weekStart) ?? throw StaffReportRefusedException::notOwed($trooper->display_name, $weekStart);
        $fields = $this->fields($command, $weekStart, $hq);
        $sections = $this->pickedSections($command, $fields['practice_type'], $hq);

        // Sent already: an edit until the deadline, with a reason; after it, locked.
        $editing = $this->reports->find($trooper->id, $weekStart) !== null;

        if ($editing && ! ReportEditWindow::isOpen($weekStart)) {
            throw StaffReportRefusedException::locked($weekStart);
        }

        if ($editing && blank($command->reason)) {
            throw StaffReportRefusedException::reasonRequired();
        }

        $public = $fields['presence_type'] === StaffReport::PRESENCE_PUBLIC_SERVER
            ? $this->publicServer->check($trooper->id, $hq, $weekStart) : ['check' => null, 'minutes' => null, 'reason' => null];
        $fields = [
            ...$fields,
            ...$this->presenceCheck($trooper->id, $weekStart, $fields['practice_type'], $sections),
            'public_server_check' => $public['check'],
            'public_server_minutes' => $public['minutes'],
            'public_server_reason' => $public['reason'],
        ];

        $report = null;
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: $editing ? self::EDITED : self::SENT,
            reason: $editing ? $command->reason : null,
            reasonRequired: $editing,
            mutation: function () use ($actor, $trooper, $weekStart, $hq, $sections, $fields, $command, $editing, &$report) {
                // The edit locks the sent row before it touches the week's entries; a first send must find none.
                if ($editing ? $this->reports->lockSent($trooper->id, $weekStart) === null : $this->reports->isSent($trooper->id, $weekStart)) {
                    throw StaffReportRefusedException::alreadySent($weekStart);
                }

                if ($editing && ! ReportEditWindow::isOpen($weekStart)) {
                    throw StaffReportRefusedException::locked($weekStart);
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

                $report = ($editing ? $this->reports->edit(...) : $this->reports->send(...))(
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
     * What the game server's presence data says about a section practice report, frozen with it: `conflict` when at
     * least one picked practice was captured that week and no picked capture saw the staff member through a linked
     * game identity, `confirmed` when one did, `no_data` when none of the picked practices was captured (no data is
     * never a conflict). `presence_source` names the system the capture reads for the section's game ("CRCON" for
     * Hell Let Loose), or "game server" when the game has none. Only a section practice is checked: "No" and the
     * named types carry nulls. The report is sent either way.
     *
     * @param  list<Unit>  $sections
     * @return array{presence_check: ?string, presence_source: ?string}
     */
    private function presenceCheck(int $trooperId, CarbonImmutable $weekStart, ?string $type, array $sections): array
    {
        if ($type !== StaffReport::SP || $sections === []) {
            return ['presence_check' => null, 'presence_source' => null];
        }

        $captured = $this->practices->forSections(array_map(fn (Unit $section) => $section->id, $sections), $weekStart)
            ->filter(fn ($practice) => $practice->captured_at !== null)
            ->keyBy('section_id');
        if ($captured->isEmpty()) {
            return ['presence_check' => StaffReport::PRESENCE_NO_DATA, 'presence_source' => $this->sourceLabel($sections[0]->id)];
        }

        $seen = $this->captures->seenInWeek([$trooperId], $weekStart)[$trooperId] ?? [];
        $hit = $captured->keys()->contains(fn ($sectionId) => isset($seen[(int) $sectionId]));

        return [
            'presence_check' => $hit ? StaffReport::PRESENCE_CONFIRMED : StaffReport::PRESENCE_CONFLICT,
            'presence_source' => $this->sourceLabel((int) $captured->keys()->first()),
        ];
    }

    /** The on-screen name of the system that captures the section's game (its telemetry interface), or "game server". */
    private function sourceLabel(int $sectionId): string
    {
        $gameId = (UnitGame::resolve($this->units->tree())[$sectionId] ?? null)?->gameId;
        $interface = $gameId === null ? null : $this->games->find($gameId)?->interface;

        return $interface === null || $interface === TelemetryInterface::None ? 'game server' : $interface->label();
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
     * The rest of what was sent, checked and trimmed: the practice type (SP unless another is picked, null with "No"),
     * checked against the HQ ({@see typesFor()}); a named type's name and date (`ftx_name` and `ftx_date`, both
     * required, the date inside the Cav week); the presence answer (required), with an office hour's date
     * and note (only for an office hour, both required); other events and notes (empty text is null).
     *
     * @return array{practice_type: ?string, ftx_name: ?string, ftx_date: ?string, presence_type: ?string, office_hour_date: ?string, office_hour_note: ?string, other_events: ?string, notes: ?string}
     *
     * @throws StaffReportRefusedException
     */
    private function fields(SendStaffReport $command, CarbonImmutable $weekStart, Unit $hq): array
    {
        $type = $command->practiceType === null || $command->practiceType === '' ? null : $command->practiceType;
        $ftxName = self::text($command->ftxName);
        $ftxDate = self::text($command->ftxDate);
        $presence = $command->presenceType === null || $command->presenceType === '' ? null : $command->presenceType;
        $officeDate = self::text($command->officeHourDate);
        $officeNote = self::text($command->officeHourNote);

        if ($type !== null && ! in_array($type, self::typesFor($hq), true)) {
            throw StaffReportRefusedException::unknownPracticeType($hq->type === UnitType::Battalion);
        }

        if (! $command->attended) {
            if (($type !== null && $type !== StaffReport::SP) || $ftxName !== null || $ftxDate !== null) {
                throw StaffReportRefusedException::ftxWithNo();
            }
            $type = null;
        } else {
            $type ??= StaffReport::SP;
        }

        $what = self::named($type);
        if ($what !== null) {
            if ($ftxName === null) {
                throw StaffReportRefusedException::ftxNameMissing($what);
            }
            if (mb_strlen($ftxName) > self::MAX_FTX_NAME) {
                throw StaffReportRefusedException::tooLong('ftx_name', "the name of {$what}", self::MAX_FTX_NAME);
            }

            if (! self::inWeek($ftxDate, $weekStart)) {
                throw StaffReportRefusedException::ftxDateOutsideWeek($weekStart, $what);
            }
        } elseif ($ftxName !== null || $ftxDate !== null) {
            // An SP has no name or date: the form clears them, so these came from somewhere else.
            throw StaffReportRefusedException::ftxFieldsWithSp();
        }

        if ($presence !== null && ! in_array($presence, StaffReport::PRESENCE_TYPES, true)) {
            throw StaffReportRefusedException::unknownPresence();
        }

        if ($presence === StaffReport::PRESENCE_OFFICE_HOUR) {
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

        // Every send and edit answers presence; only reports stored before the question was asked hold null.
        if ($presence === null) {
            throw StaffReportRefusedException::presenceMissing();
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
            'ftx_name' => $what !== null ? $ftxName : null,
            'ftx_date' => $what !== null ? $ftxDate : null,
            'presence_type' => $presence,
            'office_hour_date' => $presence === StaffReport::PRESENCE_OFFICE_HOUR ? $officeDate : null,
            'office_hour_note' => $presence === StaffReport::PRESENCE_OFFICE_HOUR ? $officeNote : null,
            'other_events' => $otherEvents,
            'notes' => $notes,
        ];
    }

    /**
     * The accountability types an HQ reports: a section practice or an FTX on a platoon or company HQ; a section
     * practice, an operation, a class or another official event on a battalion HQ.
     *
     * @return list<string>
     */
    public static function typesFor(Unit $hq): array
    {
        return $hq->type === UnitType::Battalion
            ? [StaffReport::SP, StaffReport::OPERATION, StaffReport::CLASS_TYPE, StaffReport::EVENT]
            : [StaffReport::SP, StaffReport::FTX];
    }

    /** How a named type reads in a message ("the FTX"), or null for an SP or no type. */
    private static function named(?string $type): ?string
    {
        return match ($type) {
            StaffReport::FTX => 'the FTX',
            StaffReport::OPERATION => 'the operation',
            StaffReport::CLASS_TYPE => 'the class',
            StaffReport::EVENT => 'the event',
            default => null,
        };
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
     * The sections picked, each once and in the order given, checked against the answer. Each must be under the HQ:
     * the platoon or company, or for battalion HQ staff the battalion.
     *
     * @return list<Unit>
     *
     * @throws StaffReportRefusedException
     */
    private function pickedSections(SendStaffReport $command, ?string $type, Unit $hq): array
    {
        $ids = array_values(array_unique(array_map('intval', $command->sectionIds)));

        // An FTX may span several sections or none in particular: its name and date say what it was.
        if ($command->attended && $type === StaffReport::SP && $ids === []) {
            throw StaffReportRefusedException::noPractice();
        }

        // An operation, a class or an event names itself and takes no section.
        if ($command->attended && in_array($type, [StaffReport::OPERATION, StaffReport::CLASS_TYPE, StaffReport::EVENT], true) && $ids !== []) {
            throw StaffReportRefusedException::sectionsWithEvent();
        }

        if (! $command->attended && $ids !== []) {
            throw StaffReportRefusedException::practicesWithNo();
        }

        return array_map(function (int $id) use ($hq) {
            $unit = $this->units->find($id);

            if ($unit === null || $unit->type !== UnitType::Section) {
                throw StaffReportRefusedException::notASection();
            }

            return str_starts_with((string) $unit->path, (string) $hq->path) ? $unit : throw StaffReportRefusedException::notInUnit($hq->type === UnitType::Battalion);
        }, $ids);
    }
}
