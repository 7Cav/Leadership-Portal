<?php

namespace App\Commands\Attendance;

use App\Authorization\ScopeResolver;
use App\Models\AttendanceSource;
use App\Models\SectionPractice;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\AuditedBatch;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;

/**
 * The one writer of record for `section_practices` and `attendance` (AD-22). Every manual mark —
 * the rollcall screen's submit (3.8) and anything after it — goes through here, so the audit
 * trail and the conflict rules live in exactly one place.
 *
 * Before anything is written it refuses: a section outside the actor's scope (AD-8), and any
 * trooper not placed in that section — a trooper is marked only against their own section's
 * practice (AD-14, AD-18), which also keeps a leader from reaching a trooper outside their scope.
 *
 * Then, as one audited transaction (AD-16): find-or-create the section's practice for the week
 * `WeekOf` resolves (AD-11), then write each entry's facts. Every practice mark is written with
 * `source = manual`, overwriting whatever was there — including an `auto` mark, since manual wins
 * on conflict (AD-22); the future auto-capture writer (Epic 7) is the one that must not overwrite
 * a manual mark. A failure anywhere rolls back the practice row, every mark and the log entry
 * together; the log records the batch once, targeting the practice, with a digest over every row
 * written.
 */
class MarkAttendanceHandler
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly AttendanceRepository $attendance,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws MarkAttendanceForbiddenException when the section is outside the actor's scope.
     * @throws InvalidAttendanceMarkException when a trooper is not placed in the section.
     * @throws \InvalidArgumentException when the section id is not a section (from the repository).
     */
    public function handle(User $actor, MarkAttendance $command): SectionPractice
    {
        if (! $this->scope->resolve($actor)->contains($command->sectionId)) {
            throw new MarkAttendanceForbiddenException($command->sectionId);
        }

        $trooperIds = $command->trooperIds();
        $unplaced = array_values(array_diff($trooperIds, $this->troopers->idsPlacedIn($command->sectionId, $trooperIds)->all()));

        if ($unplaced !== []) {
            throw InvalidAttendanceMarkException::notPlacedInSection($unplaced, $command->sectionId);
        }

        $weekStart = WeekOf::start($command->week);

        $batch = $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'attendance.marked',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($command, $weekStart) {
                $practice = $this->practices->findOrCreate($command->sectionId, $weekStart);
                $rows = [];

                foreach ($command->marks as $mark) {
                    if ($mark->status !== null) {
                        $row = $this->attendance->markPractice($practice, $mark->trooperId, $mark->status, AttendanceSource::Manual);
                    }

                    if ($mark->rollcall !== null) {
                        $row = $this->attendance->signRollcall($practice, $mark->trooperId, $mark->rollcall);
                    }

                    $rows[] = $row;
                }

                return new AuditedBatch($practice, $rows);
            },
        );

        return $batch->target;
    }
}
