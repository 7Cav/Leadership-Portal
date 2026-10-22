<?php

namespace App\Repositories;

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Unit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Sole DB access for `attendance` (AD-2, AD-14, AD-22). Portal-owned: 3.3's audited
 * `MarkAttendance` Command is its one creating writer. Business rules — who may mark whom,
 * refusing hand-entered `loa`, manual-wins-over-auto — belong to the callers (`MarkAttendance`
 * today; LOA derivation (3.4) and auto-capture (Epic 7) later); this layer only persists what it
 * is handed.
 *
 * A row carries two independent facts — the rollcall signature and the SP mark — so each has its
 * own write, an update-or-create keyed on `(trooper_id, section_practice_id)` (the UNIQUE key)
 * that touches only its own columns. Recording one fact can never overwrite the other, and a
 * repeat write updates the one row rather than adding a second counting mark.
 *
 * Every write here is a real one: it clears `is_demo` on the row it touches, so a leader marking
 * over generated demo history (#115) turns that row real and a demo purge leaves it alone.
 *
 * Like `SectionPracticeRepository::findOrCreate()`, each write holds inside an open transaction:
 * a racing insert of the same key is found by a locking re-read and updated rather than crashing
 * on the duplicate key. Locks are still taken, so a caller writing several rows in one
 * transaction writes them in a fixed order and retries on deadlock.
 */
interface AttendanceRepository
{
    /**
     * Record the trooper's Section Practice mark and its source (manual or auto). Leaves `rollcall` as it was
     * (`unsigned` on a new row).
     */
    public function markPractice(
        SectionPractice $practice,
        int $trooperId,
        AttendanceStatus $status,
        AttendanceSource $source,
    ): Attendance;

    /**
     * Auto-capture's write (7.6): `status = present`, `source = auto` — unless the row already
     * carries a leader's say, which manual-wins-over-auto (AD-14, AD-22) leaves untouched: a
     * `manual` mark of any status, or an `loa` mark (derived from LOA data, never overwritten by
     * capture). The guard is evaluated against a locking read inside this write's own
     * transaction, so a manual mark landing mid-capture is either seen and kept, or lands after
     * and overwrites the auto mark. An existing `auto` (or rollcall-only) row is updated in place.
     * Returns the row written, or null when it was left alone.
     */
    public function markPracticeAuto(SectionPractice $practice, int $trooperId): ?Attendance;

    /**
     * Record the trooper's signature on the week's rollcall thread. Leaves `status`/`source` as
     * they were (both null on a new row — signed, practice not yet marked).
     */
    public function signRollcall(
        SectionPractice $practice,
        int $trooperId,
        RollcallSignature $rollcall,
    ): Attendance;

    /**
     * Every attendance row against one practice — including rollcall-only rows whose `status` is
     * still null — each with its `trooper` loaded. Empty is a valid answer:
     * a practice row may exist before anyone is marked.
     *
     * @return Collection<int, Attendance>
     */
    public function forPractice(SectionPractice $practice): Collection;

    /**
     * The counting marks (AD-14) of every trooper currently assigned within `$unit`'s subtree
     * (`Trooper::assignedWithin`) over the whole weeks `$firstWeek` through `$lastWeek`
     * inclusive: one per trooper per practice their *primary* section held in that span, whether
     * or not the trooper has an attendance row on it (no row reads as a null status and an
     * unsigned rollcall). Each mark says whether its practice was *held* — at least one attendance
     * row on it, from any trooper, carries a leader's SP mark (any `status` but `loa`, which is
     * derived from LOA data); a row with only rollcall signatures or `loa` marks is not. A
     * row against any other section's practice is not a counting mark and is left out. Ordered by
     * trooper id, then week. One query, however large the subtree.
     *
     * `$includeDeparted` widens the population to departed (`inactive`) troopers still placed in
     * the subtree, so the roster (2.8) can show a departed trooper's history. The KPI service
     * never passes it: departed troopers are not measured (AD-21).
     *
     * @return Collection<int, CountingMark>
     *
     * @throws InvalidArgumentException when either bound is not a Saturday 00:00 UTC.
     */
    public function countingMarks(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek, bool $includeDeparted = false): Collection;

    /**
     * Where each of `$sectionIds` stands for the week `$weekStart` (the home step matrix, AD-14):
     * troopers assigned, rollcalls signed, practice marks recorded and whether the practice was
     * held. Keyed by section id; a section with no assigned trooper is absent. A section with no
     * practice row yet counts as nothing signed or marked. One query, however many sections.
     *
     * `$excusedTrooperIds` are troopers whose practice status is derived rather than marked (their leave covers the
     * week, 3.4, as `LoaCoverage` decides; the caller asks it, since a Repository cannot). Each counts as signed and
     * as marked, once, whether or not a row exists, so a section with someone on leave can reach its full strength on
     * both steps. They never make the practice held.
     *
     * @param  list<int>  $sectionIds
     * @param  list<int>  $excusedTrooperIds
     * @return Collection<int, SectionWeekProgress>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function weekProgress(array $sectionIds, CarbonInterface $weekStart, array $excusedTrooperIds = []): Collection;
}
