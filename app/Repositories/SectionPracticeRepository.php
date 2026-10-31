<?php

namespace App\Repositories;

use App\Models\SectionPractice;
use App\Models\Unit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Sole DB access for `section_practices` (AD-2, AD-14, AD-22). Portal-owned: 3.3's audited
 * `MarkAttendance` Command is its one creating writer. Rows are only ever found or created —
 * never truncated and reinserted — because `attendance` rows hang off their PK.
 *
 * Every `$weekStart` must be a Saturday 00:00 UTC (AD-11), given in any timezone (it is compared
 * as an instant); anything else throws `InvalidArgumentException` rather than silently keying a
 * practice to a non-week.
 */
interface SectionPracticeRepository
{
    /**
     * The section's practice row for the week, creating it if absent. Insert-or-find under the
     * `(section_id, week_start)` UNIQUE constraint, so two concurrent first marks for the same
     * section-week land on one row rather than one of them crashing on a duplicate key. Safe
     * inside an open transaction too: the insert runs under a savepoint, and if `createOrFirst`'s own
     * plain re-read misses the racing row, a locking read finds it — it sees a racing submit's
     * committed row even where a MySQL REPEATABLE READ snapshot would not.
     *
     * Finding a generated demo practice (#115) clears its `is_demo` tag: only real writers call
     * this, and the practice becomes the anchor of a real mark. A staff entry passes `$keepDemo`:
     * it is the staff member's record, nothing of the host section's own hangs off the practice, so
     * a demo week stays demo and the demo purge still clears its generated marks.
     *
     * Practices are per section (AD-14) — a practice keyed to a platoon or company would split a
     * trooper-week's count across two echelons — so a unit of any other type is refused.
     *
     * @throws InvalidArgumentException when `$sectionId` is not a `units` row of type section,
     *                                  or `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function findOrCreate(int $sectionId, CarbonInterface $weekStart, bool $keepDemo = false): SectionPractice;

    /**
     * The section's practice row for the week, or null when none has been recorded — a read that
     * never creates one. Its `rollcallPostedBy` and `confirmedBy` users are loaded (null until set).
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function find(int $sectionId, CarbonInterface $weekStart): ?SectionPractice;

    /**
     * Record that the practice's rollcall was posted to the forum (3.10): `rollcall_posted_at` =
     * now, `rollcall_posted_by` = `$userId`, overwriting any earlier mark — the latest post wins.
     * Returns the row with `rollcallPostedBy` loaded. Callers route it through the audit envelope.
     */
    /**
     * The week's practice rows of `$sectionIds`, keyed by section id; a section with none yet is
     * absent. One query, however many sections. Never creates a row.
     *
     * @param  list<int>  $sectionIds
     * @return Collection<int, SectionPractice>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function forSections(array $sectionIds, CarbonInterface $weekStart): Collection;

    /**
     * Where the section's practices stand for the weeks `$firstWeek` through `$lastWeek` inclusive
     * (the rollcall's week navigation): per practice row, the troopers currently placed in the
     * section (not inactive) who carry a practice mark, and whether the rollcall is marked posted
     * with no attendance row changed after it. Keyed by `week_start` date (`Y-m-d`); a week with
     * no practice row is absent. One grouped query. Never creates a row.
     *
     * `$excused` lists, per week (`Y-m-d`), the troopers whose leave covers it (3.4, as `LoaCoverage`
     * decides; the caller asks it, since a Repository cannot). Their marks that week are left out of
     * `marked`, so the caller can count each of them once as having nothing to mark.
     *
     * @param  array<string, list<int>>  $excused
     * @return Collection<string, PracticeWeekStatus>
     *
     * @throws InvalidArgumentException when either bound is not a Saturday 00:00 UTC.
     */
    public function weekStatuses(int $sectionId, CarbonInterface $firstWeek, CarbonInterface $lastWeek, array $excused = []): Collection;

    public function markRollcallPosted(SectionPractice $practice, int $userId): SectionPractice;

    /**
     * Record that a leader reviewed and confirmed the practice's marks (7.8): `confirmed_at` = now,
     * `confirmed_by` = `$userId`; confirming again re-stamps. Returns the row with `confirmedBy`
     * loaded. Callers route it through the audit envelope.
     */
    public function confirm(SectionPractice $practice, int $userId): SectionPractice;

    /**
     * Clear the confirmation, because a mark changed after it (7.8). A no-op on an unconfirmed
     * practice. Runs inside the audited mark write that changed the mark.
     */
    public function unconfirm(SectionPractice $practice): SectionPractice;

    /**
     * The practice row with this id, or null — the capture run's lookup (7.5). Never creates.
     */
    public function findById(int $id): ?SectionPractice;

    /**
     * The practice row with this id, read with a row lock (`FOR UPDATE`), or null. Called inside an
     * open transaction to decide on the practice's current state atomically with the writes that
     * follow (7.8: a confirmation commits before or after a pre-mark, never between its check and
     * its writes). Outside a transaction the lock is released at once.
     */
    public function lockById(int $id): ?SectionPractice;

    /**
     * Stamp the practice with where a capture read from and when (7.5, AD-19): `server_ref`,
     * `captured_at` and `capture_source`. Re-stamping overwrites — a re-run is idempotent on the
     * same row. `window_start`/`window_end` keep the window the capture read (#392), so a later
     * change of the section's slot never rewrites it. The caller routes it through the audit envelope
     * where an actor exists.
     */
    public function stampCapture(SectionPractice $practice, string $serverRef, \DateTimeInterface $capturedAt, string $captureSource, ?\DateTimeInterface $windowStart = null, ?\DateTimeInterface $windowEnd = null): SectionPractice;

    /**
     * Move this week's practice to `$movedTo` or cancel it (#392), or with neither put it back on the section's slot.
     * The caller routes it through the audit envelope.
     */
    public function changeWeek(SectionPractice $practice, ?\DateTimeInterface $movedTo, bool $cancelled): SectionPractice;

    /**
     * Whether any practice, demo or real, has been recorded for `$unit` —
     * `section_practices.section_id` restricts deletes, so `DeleteUnit` checks this first to
     * refuse with a reason instead of a raw constraint error.
     */
    public function hasPracticesFor(Unit $unit): bool;

    /**
     * Delete the practice when nothing hangs off it any more (audit 2, L1): no attendance mark or signature, no
     * guest, staff entry or game server sighting, no capture (stamped, saved players or a requested run), and its
     * rollcall neither posted nor confirmed. What a staff entry created and its removal left empty. One statement,
     * so a mark written meanwhile keeps the row. Whether it was deleted.
     */
    public function deleteIfUnused(SectionPractice $practice): bool;
}
