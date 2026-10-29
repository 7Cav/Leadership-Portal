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
     * The automatic makeup write (decided 2026-10-23): `status = makeup`, `source = auto` on the
     * trooper's own section's practice, for a trooper seen at another section's practice that week
     * (a host leader's hand-added guest; not called by the capture until practice time slots exist). It never downgrades and never overrides a leader: a row that already carries a
     * `manual` mark of any status, an `loa` mark, or an attended mark (`present` or `attached`,
     * whatever its source) is left untouched. An unmarked row (none yet, or rollcall-only) and an
     * `auto` `missed` row become the makeup; an existing auto makeup is kept in place. The guard is
     * evaluated against a locking read inside this write's own transaction, as in
     * {@see markPracticeAuto()}, which is also what later replaces an auto makeup with `present` when
     * the trooper's own practice is captured and sees them. Returns the row written or kept as the
     * auto makeup, or null when it was left alone.
     */
    public function markMakeupAuto(SectionPractice $practice, int $trooperId): ?Attendance;

    /**
     * Take back an automatic makeup (decided 2026-10-23): when the host leader removes the guest entry that alone
     * supported it, the `makeup`/`auto` mark {@see markMakeupAuto()} wrote on the trooper's own section's practice is
     * cleared to unmarked (`status` and `source` null; the row and its rollcall signature stay). Anything else is left
     * exactly as it is: a leader's manual mark (a makeup marked by hand included), `present` or `attached` (the
     * trooper's own practice was captured since), `loa`, `missed`, or no row. Decided against a locking read inside
     * this write's own transaction, as {@see markMakeupAuto()} is. Returns the cleared row, or null when nothing was
     * cleared.
     */
    public function clearMakeupAuto(SectionPractice $practice, int $trooperId): ?Attendance;

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
     * inclusive: one per trooper per week, against the practice of the section they were placed in
     * that week (`TrooperPlacementRepository::sectionsByWeek`, 2026-10-27; their current section
     * for a trooper with no placement history), whether or not the trooper has an attendance row
     * on it (no row reads as a null status and an unsigned rollcall). A week with no practice row
     * at that section has no mark, and so does a week they were placed in no section. After a
     * transfer their marks at the old section stay theirs, and the new section's earlier practices
     * are not. In the week of the move, the practice they attended, else the one they were marked
     * at, else the later one; a rollcall signed at either counts. Each mark says whether its practice was *held* — at least one attendance
     * row on it, from any trooper, carries a leader's SP mark (any `status` but `loa`, which is
     * derived from LOA data); a row with only rollcall signatures or `loa` marks is not, and neither
     * is one whose only marks are automatic makeups: an auto makeup records a practice the trooper
     * attended elsewhere, and says nothing about whether their own section practised. A
     * row against a practice of a section they were not placed in that week is not a counting mark
     * and is left out. Ordered by trooper id, then week. Two queries, however large the subtree.
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
     * {@see countingMarks()} with what the KPI service needs beside the marks, in the same two queries: every trooper
     * of the population with their current section, and the weeks each was placed in a section (2026-10-27).
     *
     * @throws InvalidArgumentException when either bound is not a Saturday 00:00 UTC.
     */
    public function countingWindow(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek, bool $includeDeparted = false): CountingWindow;

    /**
     * {@see countingWindow()} for a week that has ended (the 2026-10-29 audit): the population is who counted in `$unit`
     * that week, `$lastWeek`, by the placement history ({@see TrooperPlacementRepository::sectionsByWeekWithin()}),
     * not who is assigned now, so a trooper who moved or left after the week stays in it. Each trooper's section in
     * the window is the one they ended `$lastWeek` in. Departed troopers who served that week are measured too. The
     * same two queries.
     *
     * @throws InvalidArgumentException when either bound is not a Saturday 00:00 UTC.
     */
    public function countingWindowAsPlaced(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek): CountingWindow;

    /**
     * {@see countingMarks()} for a set of troopers instead of a subtree: each serving, placed trooper of `$trooperIds`
     * against the practices of the sections they were placed in, whichever sections those are, with `held` decided by
     * the same rule. For a screen that measures troopers from many sections at once (the JSAM guests' own SP) in one
     * read rather than one per section. An unplaced trooper has no marks, and neither has a departed one unless
     * `$includeDeparted` (a milpac showing a departed trooper's history). Ordered by trooper id, then week.
     *
     * @param  list<int>  $trooperIds
     * @return Collection<int, CountingMark>
     *
     * @throws InvalidArgumentException when either bound is not a Saturday 00:00 UTC.
     */
    public function countingMarksOf(array $trooperIds, CarbonInterface $firstWeek, CarbonInterface $lastWeek, bool $includeDeparted = false): Collection;

    /**
     * Where each of `$sectionIds` stands for the week `$weekStart` (the home step matrix, AD-14):
     * troopers assigned, rollcalls signed, practice marks recorded, whether the practice was held,
     * and for the rollcall's section picker the troopers on LOA, the unsigned ones and when the
     * rollcall was marked posted. Keyed by section id; a section with no assigned trooper is absent. A section with no
     * practice row yet counts as nothing signed or marked. One query, however many sections.
     *
     * `$excusedTrooperIds` are troopers whose practice status is derived rather than marked (their leave covers the
     * week, 3.4, as `LoaCoverage` decides; the caller asks it, since a Repository cannot). Each counts as signed, as
     * marked and as on LOA, once, whether or not a row exists, and never as unsigned, so a section with someone on
     * leave can reach its full strength on both steps. They never make the practice held, and
     * neither does an automatic makeup (the rule {@see countingMarks()} uses); a trooper carrying one
     * still counts as marked.
     *
     * The troopers counted are the serving ones placed in each section now, unless `$roster` says otherwise: for a
     * week that has ended, the rollcalls it ended with ({@see TrooperRepository::onRollcalls()}, the 2026-10-29
     * audit), trooper id => section id, so the counts agree with each rollcall after a move or a departure. Each
     * trooper counts by their row on that section's practice only. Still one query.
     *
     * @param  list<int>  $sectionIds
     * @param  list<int>  $excusedTrooperIds
     * @param  array<int, int>|null  $roster
     * @return Collection<int, SectionWeekProgress>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function weekProgress(array $sectionIds, CarbonInterface $weekStart, array $excusedTrooperIds = [], ?array $roster = null): Collection;
}
