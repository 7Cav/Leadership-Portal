<?php

namespace App\Commands\Attendance;

use App\Authorization\ScopeResolver;
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
 * Then, as one audited transaction (AD-16): find-or-create the section's practice for the week
 * `WeekOf` resolves (AD-11), then write each entry's facts in trooper-id order (a fixed lock order,
 * so two overlapping submits cannot deadlock each other). Every practice mark is written with
 * `source = manual`, overwriting whatever was there — including an `auto` mark, since manual wins
 * on conflict (AD-22); the auto-capture path (Epic 7), whatever writes `source = auto`, is
 * the one that must not overwrite a manual mark — which is {@see preMark()}, below. A failure anywhere rolls back the practice row,
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
    ) {}

    /**
     * @throws MarkAttendanceForbiddenException when the section is outside the actor's scope.
     * @throws InvalidAttendanceMarkException when the unit is not a section, or a trooper is not
     *                                        placed in it.
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

                $practice = $this->practices->findOrCreate($command->sectionId, $weekStart);
                $rows = [];

                // A hand edit of a practice mark makes an earlier confirmation (7.8) stale: the
                // leader confirms again once they have reviewed the marks as they now stand.
                if ($command->hasPracticeMarks()) {
                    $this->practices->unconfirm($practice);
                }

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
     * Capture's pre-mark (7.6, AD-14/AD-22): `present` with `source = auto` for each trooper the
     * capture resolved to this practice's section — the same writer as a manual mark, a second
     * *source* rather than a second writer. Manual wins: a trooper whose row already carries a
     * `manual` mark (or `loa`) is left exactly as it is, decided per row at write time by the
     * repository. Re-running updates auto rows in place. Never writes `loa`, and skips a trooper
     * no longer placed in the practice's section.
     *
     * No actor authorization: capture is system-triggered, like the roster sync (the caller
     * decides who may start a run). A user-triggered run is audited as one batch on the practice;
     * a `'system'` run is covered by its `sync_runs` row (AD-16's carve-out for system jobs).
     * Writes go in trooper-id order, the same lock order as {@see handle()}.
     *
     * @param  list<int>  $trooperIds
     */
    public function preMark(SectionPractice $practice, array $trooperIds, int|string $triggeredBy = 'system'): AutoMarkResult
    {
        $trooperIds = array_values(array_unique($trooperIds));
        sort($trooperIds);

        // A confirmed practice (7.8) is locked for reporting: capture re-runs and links leave its
        // marks exactly as the leader confirmed them. A hand edit unlocks it.
        // Checked here so a locked practice costs no audit entry, and again under a row lock inside
        // the audited transaction, so a confirmation cannot land between the check and the writes.
        $locked = fn (?SectionPractice $current) => $current?->confirmed_at !== null;

        if ($locked($this->practices->findById($practice->id))) {
            return new AutoMarkResult(marked: 0, keptManual: 0, notPlaced: 0, confirmed: true);
        }

        $written = [];
        $notPlaced = 0;
        $confirmed = false;
        $mutation = function () use ($practice, $trooperIds, $locked, &$written, &$notPlaced, &$confirmed) {
            $written = [];

            if ($confirmed = $locked($this->practices->lockById($practice->id))) {
                return new AuditedBatch($practice);
            }

            $placed = $this->troopers->idsPlacedIn($practice->section_id, $trooperIds)->all();
            $notPlaced = count($trooperIds) - count($placed);

            foreach ($trooperIds as $trooperId) {
                if (! in_array($trooperId, $placed, true)) {
                    continue;
                }

                $row = $this->attendance->markPracticeAuto($practice, $trooperId);

                if ($row !== null) {
                    $written[] = $row;
                }
            }

            return new AuditedBatch($practice, ...$written);
        };

        if (is_int($triggeredBy) && $trooperIds !== []) {
            $this->audit->recordBatch($triggeredBy, 'attendance.auto_marked', null, false, $mutation);
        } else {
            $mutation();
        }

        if ($confirmed) {
            return new AutoMarkResult(marked: 0, keptManual: 0, notPlaced: 0, confirmed: true);
        }

        $marked = count($written);

        return new AutoMarkResult(marked: $marked, keptManual: count($trooperIds) - $notPlaced - $marked, notPlaced: $notPlaced);
    }
}
