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
use App\Repositories\WeeklyReportRepository;
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
 * It also refuses a locked week: once the section's weekly report for the week has been really
 * sent (not a generated demo report, #115), its facts are frozen (AD-12) and the rollcall of that
 * week may no longer change — the whole batch, signature-only and empty ones included, is refused
 * with `RollcallWeekLockedException`. Earlier weeks whose report was never sent stay editable.
 * The check is the first thing inside the transaction and holds a shared lock on the report row
 * (`WeeklyReportRepository::isSentFor()`), so a send landing mid-save either waits for the save
 * to commit or is seen by it — never a save written over a week that was frozen under it. The
 * capture pre-mark ({@see preMark()}) respects the same lock, answering `weekLocked` instead.
 *
 * Then, as one audited transaction (AD-16): find-or-create the section's practice for the week
 * `WeekOf` resolves (AD-11), then write each entry's facts in trooper-id order (a fixed lock order,
 * so two overlapping submits cannot deadlock each other). Every practice mark is written with
 * `source = manual`, overwriting whatever was there — including an `auto` mark, since manual wins
 * on conflict (AD-22); the auto-capture path (Epic 7), whatever writes `source = auto`, is
 * the one that must not overwrite a manual mark — which is {@see preMark()}, below, and its
 * makeup counterpart {@see preMarkMakeups()} for a trooper seen at another section's practice (still
 * written only on the trooper's own section's practice). A failure anywhere rolls back the practice row,
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
        private readonly WeeklyReportRepository $reports,
        private readonly AuditEnvelope $audit,
        private readonly LoaCoverage $loaCoverage,
    ) {}

    /**
     * @throws MarkAttendanceForbiddenException when the section is outside the actor's scope.
     * @throws InvalidAttendanceMarkException when the unit is not a section, a trooper is not placed in it,
     *                                        a trooper's leave covers the week and so derives their status,
     *                                        or the week is before `WeekOf::floor()`.
     * @throws RollcallWeekLockedException when the section's report for the week has been sent.
     */
    public function handle(User $actor, MarkAttendance $command): SectionPractice
    {
        if (! $this->scope->writeScope($actor)->contains($command->sectionId)) {
            throw new MarkAttendanceForbiddenException($command->sectionId);
        }

        if ($this->units->find($command->sectionId)?->type !== UnitType::Section) {
            throw InvalidAttendanceMarkException::notASection($command->sectionId);
        }

        $weekStart = WeekOf::start($command->week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw InvalidAttendanceMarkException::weekBeforeFloor($weekStart);
        }

        $marks = $command->marks;
        usort($marks, fn (AttendanceMark $a, AttendanceMark $b) => $a->trooperId <=> $b->trooperId);

        $batch = $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'attendance.marked',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($command, $weekStart, $marks) {
                // First, before any write: a sent report freezes the week (AD-12). The shared lock
                // it takes holds until this transaction ends, so a concurrent send cannot freeze
                // the week between this check and the marks below.
                if ($this->reports->isSentFor($command->sectionId, $weekStart)) {
                    throw RollcallWeekLockedException::for($command->sectionId, $weekStart);
                }

                $trooperIds = $command->trooperIds();
                $unplaced = array_values(array_diff($trooperIds, $this->troopers->idsPlacedIn($command->sectionId, $trooperIds)->all()));

                if ($unplaced !== []) {
                    throw InvalidAttendanceMarkException::notPlacedInSection($unplaced, $command->sectionId);
                }

                $this->refuseLeaveCovered($marks, $weekStart);

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

    /**
     * Capture's pre-mark (7.6, AD-14/AD-22): `present` with `source = auto` for each trooper the
     * capture resolved to this practice's section — the same writer as a manual mark, a second
     * *source* rather than a second writer. Manual wins: a trooper whose row already carries a
     * `manual` mark (or `loa`) is left exactly as it is, decided per row at write time by the
     * repository. Re-running updates auto rows in place, an automatic makeup included: a trooper
     * {@see preMarkMakeups()} credited for another section's practice who is then seen at their own
     * becomes `present`. Never writes `loa`, and skips a trooper
     * no longer placed in the practice's section or no longer serving (a departed trooper keeps
     * their last placement). A trooper the LOA data covers that week is skipped too, by the same
     * {@see LoaCoverage} rule that refuses a manual mark: their status is derived as `loa`, and an
     * auto `present` would be a stored mark the leader could not then change (3.4).
     *
     * No actor authorization: capture is system-triggered, like the roster sync (the caller
     * decides who may start a run). A user-triggered run is audited as one batch on the practice;
     * a `'system'` run is covered by its `sync_runs` row (AD-16's carve-out for system jobs).
     * Writes go in trooper-id order, the same lock order as {@see handle()}.
     *
     * A capture is a change to the week's attendance like any other, so it respects the same lock
     * as {@see handle()}: once the section's weekly report for the practice's week has been really
     * sent (not a demo report, #115), nothing is written and the result says `weekLocked` — an
     * outcome for the capture run to record, not an exception that would fail and retry a queued
     * job. It is checked before the 7.8 confirmation, so a sent week wins over a confirmed practice,
     * and again first in the mutation. A user-triggered run holds its shared lock on the
     * report row for the whole audited transaction, as a manual save does, and a locked one rolls
     * back without an audit entry. A `'system'` run writes outside one transaction (there is no
     * actor to audit it under), so its check only stops a run whose week was sent before it began.
     *
     * @param  list<int>  $trooperIds
     */
    public function preMark(SectionPractice $practice, array $trooperIds, int|string $triggeredBy = 'system'): AutoMarkResult
    {
        $trooperIds = array_values(array_unique($trooperIds));
        sort($trooperIds);

        // A sent week (AD-12) is locked for everything, so it is answered before the confirmation: a
        // practice that is both reads as `weekLocked`. Checked again first inside the mutation below.
        if ($this->reports->isSentFor($practice->section_id, $practice->week_start)) {
            return AutoMarkResult::weekLocked();
        }

        // A confirmed practice (7.8) is locked for reporting: capture re-runs and links leave its
        // marks exactly as the leader confirmed them. A hand edit unlocks it.
        // Checked here so a locked practice costs no transaction, and again under a row lock inside
        // the audited transaction, so a confirmation cannot land between the check and the writes;
        // one that did rolls the transaction back, so it costs no audit entry either.
        $locked = fn (?SectionPractice $current) => $current?->confirmed_at !== null;

        if ($locked($this->practices->findById($practice->id))) {
            return new AutoMarkResult(marked: 0, keptManual: 0, notPlaced: 0, confirmed: true);
        }

        $written = [];
        $notPlaced = 0;
        $mutation = function () use ($practice, $trooperIds, $locked, &$written, &$notPlaced) {
            if ($this->reports->isSentFor($practice->section_id, $practice->week_start)) {
                throw RollcallWeekLockedException::for($practice->section_id, $practice->week_start);
            }

            $written = [];

            // Confirmed between the check above and this lock: nothing to write, and thrown rather than returned as
            // an empty batch, so the audited transaction rolls back without an `attendance.auto_marked` entry.
            if ($locked($this->practices->lockById($practice->id))) {
                throw new PracticeConfirmedMeanwhileException($practice);
            }

            $placed = $this->troopers->servingIdsPlacedIn($practice->section_id, $trooperIds)->all();
            $notPlaced = count($trooperIds) - count($placed);
            $covers = $placed === [] ? [] : $this->loaCoverage->forTroopers($placed, $practice->week_start);

            foreach ($trooperIds as $trooperId) {
                if (! in_array($trooperId, $placed, true) || $covers[$trooperId]->derivedAttendanceStatus() !== null) {
                    continue;
                }

                $row = $this->attendance->markPracticeAuto($practice, $trooperId);

                if ($row !== null) {
                    $written[] = $row;
                }
            }

            return new AuditedBatch($practice, ...$written);
        };

        try {
            if (is_int($triggeredBy) && $trooperIds !== []) {
                $this->audit->recordBatch($triggeredBy, 'attendance.auto_marked', null, false, $mutation);
            } else {
                $mutation();
            }
        } catch (RollcallWeekLockedException) {
            return AutoMarkResult::weekLocked();
        } catch (PracticeConfirmedMeanwhileException) {
            return new AutoMarkResult(marked: 0, keptManual: 0, notPlaced: 0, confirmed: true);
        }

        $marked = count($written);

        return new AutoMarkResult(marked: $marked, keptManual: count($trooperIds) - $notPlaced - $marked, notPlaced: $notPlaced);
    }

    /**
     * Capture's makeup (decided by the product owner 2026-10-23, refining AD-14): a trooper is on
     * exactly one rollcall, their primary section's, so a trooper the capture saw at *another*
     * section's practice (`$host`) has made up their own section's practice. Each of `$trooperIds`
     * gets `makeup` with `source = auto` on their own section's practice for the host's Cav week —
     * the same week, never an older or a later one, found or created through
     * {@see SectionPracticeRepository::findOrCreate()} — the same writer and second *source* as
     * {@see preMark()}, through {@see AttendanceRepository::markMakeupAuto()}.
     *
     * It never downgrades and never overrides a leader: a manual mark of any status, an `loa` mark and
     * an attended mark (`present`, `attached`) stay as they are, so a trooper present at their own
     * practice keeps `present`; an unmarked trooper and an automatic `missed` become the makeup. When
     * the trooper's own practice is captured later and sees them, {@see preMark()} replaces the auto
     * makeup with `present`. A makeup a leader marks by hand is a manual mark like any other and
     * counts the same. The usual guards hold per own section, decided before its practice row is
     * created so a skipped trooper costs no empty row: nothing is written into a week before
     * `WeekOf::floor()`, a week whose weekly report has been really sent (AD-12, checked again first
     * inside the mutation, as in {@see preMark()}), or a practice confirmed by its leader (7.8, checked
     * again under the row lock; one confirmed meanwhile rolls back without an audit entry). A
     * trooper no longer serving in their section, or one whose leave covers the week (3.4, the same
     * {@see LoaCoverage} rule), is skipped. Placement and leave are read again inside the mutation,
     * which is what decides.
     *
     * A user-triggered run is audited as one `attendance.auto_marked` batch per own section, on
     * that section's practice; a `'system'` run is covered by its `sync_runs` row (AD-16). Sections
     * go in id order and troopers in id order within each, a fixed lock order.
     *
     * @param  list<int>  $trooperIds
     */
    public function preMarkMakeups(SectionPractice $host, array $trooperIds, int|string $triggeredBy = 'system'): MakeupMarkResult
    {
        $trooperIds = array_values(array_unique($trooperIds));
        sort($trooperIds);

        if ($trooperIds === []) {
            return new MakeupMarkResult;
        }

        // The makeup lands in the host's week, so a host practice before the floor has no week to write to.
        if (WeekOf::isBeforeFloor($host->week_start)) {
            return new MakeupMarkResult(locked: count($trooperIds));
        }

        $troopers = $this->troopers->byIds($trooperIds);
        $result = new MakeupMarkResult;
        $bySection = [];

        foreach ($trooperIds as $trooperId) {
            $sectionId = $troopers->get($trooperId)?->primary_section_id;

            // Unplaced (no section of their own to make up), or the host's own trooper, whom preMark() handles.
            if ($sectionId === null || $sectionId === $host->section_id) {
                $result = $result->plus(new MakeupMarkResult(kept: 1));

                continue;
            }

            $bySection[$sectionId][] = $trooperId;
        }

        ksort($bySection);

        foreach ($bySection as $sectionId => $ids) {
            $result = $result->plus($this->makeupIn($sectionId, $host->week_start, $ids, $triggeredBy));
        }

        return $result;
    }

    /**
     * {@see preMarkMakeups()} for the troopers of one own section.
     *
     * @param  list<int>  $trooperIds  in id order
     */
    private function makeupIn(int $sectionId, CarbonImmutable $weekStart, array $trooperIds, int|string $triggeredBy): MakeupMarkResult
    {
        $all = count($trooperIds);

        // A primary section that is not a section unit has no practice to hold the makeup.
        if ($this->units->find($sectionId)?->type !== UnitType::Section) {
            return new MakeupMarkResult(kept: $all);
        }

        if ($this->reports->isSentFor($sectionId, $weekStart) || $this->practices->find($sectionId, $weekStart)?->confirmed_at !== null) {
            return new MakeupMarkResult(locked: $all);
        }

        // Nobody here could take a makeup: leave the section's week without a practice row rather
        // than create an empty one.
        if ($this->makeupCandidates($sectionId, $weekStart, $trooperIds) === []) {
            return new MakeupMarkResult(kept: $all);
        }

        $written = [];
        $mutation = function () use ($sectionId, $weekStart, $trooperIds, &$written) {
            if ($this->reports->isSentFor($sectionId, $weekStart)) {
                throw RollcallWeekLockedException::for($sectionId, $weekStart);
            }

            $practice = $this->practices->findOrCreate($sectionId, $weekStart);

            if ($this->practices->lockById($practice->id)?->confirmed_at !== null) {
                throw new PracticeConfirmedMeanwhileException($practice);
            }

            $written = [];

            foreach ($this->makeupCandidates($sectionId, $weekStart, $trooperIds) as $trooperId) {
                $row = $this->attendance->markMakeupAuto($practice, $trooperId);

                if ($row !== null) {
                    $written[] = $row;
                }
            }

            return new AuditedBatch($practice, ...$written);
        };

        try {
            is_int($triggeredBy)
                ? $this->audit->recordBatch($triggeredBy, 'attendance.auto_marked', null, false, $mutation)
                : $mutation();
        } catch (RollcallWeekLockedException|PracticeConfirmedMeanwhileException) {
            return new MakeupMarkResult(locked: $all);
        }

        return new MakeupMarkResult(marked: count($written), kept: $all - count($written));
    }

    /**
     * Which of `$trooperIds` may take an automatic mark on their section's practice this week: still
     * serving and placed there, and not covered by leave (whose status is derived as `loa`, 3.4).
     *
     * @param  list<int>  $trooperIds
     * @return list<int>
     */
    private function makeupCandidates(int $sectionId, CarbonImmutable $weekStart, array $trooperIds): array
    {
        $placed = $this->troopers->servingIdsPlacedIn($sectionId, $trooperIds)->all();

        if ($placed === []) {
            return [];
        }

        $covers = $this->loaCoverage->forTroopers($placed, $weekStart);

        return array_values(array_filter(
            $trooperIds,
            fn (int $trooperId) => in_array($trooperId, $placed, true) && $covers[$trooperId]->derivedAttendanceStatus() === null,
        ));
    }
}
