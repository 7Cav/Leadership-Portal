<?php

namespace App\Commands\Attendance;

use App\Authorization\ScopeResolver;
use App\Loa\LoaCoverage;
use App\Models\AttendanceSource;
use App\Models\SectionPractice;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\AuditedBatch;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * The one writer of record for `section_practices` and `attendance` (AD-22). Every manual mark —
 * the rollcall screen's submit (3.8) and anything after it — goes through here, so the audit
 * trail and the conflict rules live in exactly one place.
 *
 * It refuses, before anything is written: a section outside the actor's scope (AD-8), a unit that
 * is not a section, and any trooper not placed in that section — a trooper is marked only against
 * their own section's practice (AD-14, AD-18), which also keeps a leader from reaching a trooper
 * outside their scope. The placement check runs inside the transaction, so a roster sync landing
 * mid-submit cannot slip a moved trooper through.
 *
 * A practice mark for a trooper the LOA data covers that week is refused too (Story 3.4): their status is
 * derived as `loa` from the synced leave, read through the one {@see LoaCoverage} definition, never chosen
 * by hand, so a hand-entered mark cannot collide with it. A leave that needs review is not coverage, so that
 * trooper is marked as usual (AD-4). A rollcall signature is a separate fact and is not refused. With no
 * leave cached at all nobody is covered and every trooper is markable.
 *
 * Then, as one audited transaction (AD-16): find-or-create the section's practice for the week
 * `WeekOf` resolves (AD-11), then write each entry's facts in trooper-id order (a fixed lock order,
 * so two overlapping submits cannot deadlock each other). Every practice mark is written with
 * `source = manual`, overwriting whatever was there — including an `auto` mark, since manual wins
 * on conflict (AD-22); the future auto-capture path (Epic 7), whatever writes `source = auto`, is
 * the one that must not overwrite a manual mark. A failure anywhere rolls back the practice row,
 * every mark and the log entry together; the log records the batch once, targeting the practice,
 * with a digest over every row written.
 */
class MarkAttendanceHandler
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly AttendanceRepository $attendance,
        private readonly AuditEnvelope $audit,
        private readonly LoaCoverage $loaCoverage,
    ) {}

    /**
     * @throws MarkAttendanceForbiddenException when the section is outside the actor's scope.
     * @throws InvalidAttendanceMarkException when the unit is not a section, a trooper is not placed in it,
     *                                        or a trooper's leave covers the week and so derives their status.
     */
    public function handle(User $actor, MarkAttendance $command): SectionPractice
    {
        if (! $this->scope->resolve($actor)->contains($command->sectionId)) {
            throw new MarkAttendanceForbiddenException($command->sectionId);
        }

        if ($this->units->find($command->sectionId)?->type !== UnitType::Section) {
            throw InvalidAttendanceMarkException::notASection($command->sectionId);
        }

        $weekStart = WeekOf::start($command->week);
        $marks = $command->marks;
        usort($marks, fn (AttendanceMark $a, AttendanceMark $b) => $a->trooperId <=> $b->trooperId);

        $batch = $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'attendance.marked',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($command, $weekStart, $marks) {
                $trooperIds = $command->trooperIds();
                $unplaced = array_values(array_diff($trooperIds, $this->troopers->idsPlacedIn($command->sectionId, $trooperIds)->all()));

                if ($unplaced !== []) {
                    throw InvalidAttendanceMarkException::notPlacedInSection($unplaced, $command->sectionId);
                }

                $this->refuseLeaveCovered($marks, $weekStart);

                $practice = $this->practices->findOrCreate($command->sectionId, $weekStart);
                $rows = [];

                foreach ($marks as $mark) {
                    if ($mark->status !== null) {
                        $row = $this->attendance->markPractice($practice, $mark->trooperId, $mark->status, AttendanceSource::Manual);
                    }

                    if ($mark->rollcall !== null) {
                        $row = $this->attendance->signRollcall($practice, $mark->trooperId, $mark->rollcall);
                    }

                    $rows[] = $row;
                }

                return new AuditedBatch($practice, ...$rows);
            },
        );

        return $batch->target;
    }

    /**
     * Refuse the whole batch when it sets a practice status for a trooper whose leave covers the week. Runs before
     * any row is written, so nothing is half-saved.
     *
     * @param  list<AttendanceMark>  $marks
     *
     * @throws InvalidAttendanceMarkException
     */
    private function refuseLeaveCovered(array $marks, CarbonImmutable $weekStart): void
    {
        $withStatus = array_values(array_map(
            fn (AttendanceMark $mark) => $mark->trooperId,
            array_filter($marks, fn (AttendanceMark $mark) => $mark->status !== null),
        ));

        if ($withStatus === []) {
            return;
        }

        $covers = $this->loaCoverage->forTroopers($withStatus, $weekStart);
        $covered = array_values(array_filter(
            $withStatus,
            fn (int $trooperId) => $covers[$trooperId]->derivedAttendanceStatus() !== null,
        ));

        if ($covered !== []) {
            throw InvalidAttendanceMarkException::loaCovered($covered);
        }
    }
}
