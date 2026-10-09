<?php

namespace App\Repositories;

use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Unit;
use App\Models\WeeklyReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The explicit, tagged demo writer (#115, AD-22) for `section_practices`, `attendance` and
 * `weekly_reports`. Every row it creates carries `is_demo = true`, and every delete it performs is
 * restricted to `is_demo` rows, so generated history never overwrites or removes a real record.
 * The real writers (`MarkAttendance`, `SendWeeklyReport`) stay the only writers of real rows.
 *
 * Writes are batched — a battalion's week is a handful of statements, not one per trooper — and
 * hold inside the caller's transaction (the audited envelope, one per week). Callers must have
 * checked {@see weeksWithRealHistory()} first: the inserts here assume no real row competes for
 * the same UNIQUE key.
 *
 * Every `$weekStart` must be a Saturday 00:00 UTC (AD-11); anything else throws
 * `InvalidArgumentException`.
 */
interface DemoHistoryRepository
{
    /**
     * `$root` and every unit beneath it, ordered by `path` (depth-first).
     *
     * @return Collection<int, Unit>
     */
    public function subtreeUnits(Unit $root): Collection;

    /**
     * The troopers currently assigned within `$root`'s subtree (the KPI population,
     * `Trooper::assignedWithin`), grouped by primary section: section id => trooper ids
     * ascending. Sections with nobody placed are absent.
     *
     * @return Collection<int, list<int>>
     */
    public function placedTroopers(Unit $root): Collection;

    /**
     * Placed-trooper counts per section across the whole tree (section id => count), for a picker
     * to show how many troopers a unit's subtree holds.
     *
     * @return Collection<int, int>
     */
    public function placedCountsBySection(): Collection;

    /**
     * Which of `$weekStarts` already hold real (non-demo) history for the subtree: a practice of
     * one of `$sectionIds`, an attendance row on any of their practices, or a report of one of
     * `$unitIds`. Such a week must not be generated over.
     *
     * @param  list<int>  $sectionIds
     * @param  list<int>  $unitIds
     * @param  list<CarbonInterface>  $weekStarts
     * @return list<CarbonImmutable> the affected weeks, ascending
     *
     * @throws InvalidArgumentException when a week is not a Saturday 00:00 UTC.
     */
    public function weeksWithRealHistory(array $sectionIds, array $unitIds, array $weekStarts): array;

    /**
     * Delete the subtree's demo rows for one week — attendance, then practices, then reports — so
     * the week can be generated afresh (rerunning is idempotent). Real rows are never touched.
     *
     * @param  list<int>  $sectionIds
     * @param  list<int>  $unitIds
     */
    public function clearWeek(array $sectionIds, array $unitIds, CarbonInterface $weekStart): void;

    /**
     * Insert one demo practice per section for the week, in one statement.
     *
     * @param  list<int>  $sectionIds
     * @return Collection<int, SectionPractice> keyed by section id
     */
    public function createPractices(array $sectionIds, CarbonInterface $weekStart): Collection;

    /**
     * Insert demo attendance rows in batches, each with `source = manual`.
     *
     * @param  list<array{trooper_id: int, section_practice_id: int, status: AttendanceStatus, rollcall: RollcallSignature}>  $rows
     * @return int rows inserted
     */
    public function insertAttendance(array $rows): int;

    /**
     * Insert the unit-week's report as an already-sent demo row: the frozen `$kpis` snapshot,
     * `$notes`, `sent_at` now, `sent_by` and `content_digest` — the same columns a real send sets.
     *
     * @param  array<string, mixed>  $kpis
     */
    public function createSentReport(
        int $unitId,
        CarbonInterface $weekStart,
        array $kpis,
        ?string $notes,
        int $sentBy,
        string $contentDigest,
    ): WeeklyReport;

    /**
     * The demo rows currently held for the subtree (or the whole portal when both lists are null).
     *
     * @param  list<int>|null  $sectionIds
     * @param  list<int>|null  $unitIds
     */
    public function count(?array $sectionIds = null, ?array $unitIds = null): DemoHistoryCounts;

    /**
     * Delete every demo row of the subtree and nothing else: demo attendance on the sections'
     * practices, the demo practices no real attendance row still hangs off, and the units' demo
     * reports. Returns what was deleted.
     *
     * @param  list<int>  $sectionIds
     * @param  list<int>  $unitIds
     */
    public function purge(array $sectionIds, array $unitIds): DemoHistoryCounts;
}
