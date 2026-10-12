<?php

namespace App\Repositories;

use App\Models\Unit;
use App\Models\WeeklyReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Sole DB access for `weekly_reports` (AD-2, AD-12, AD-22). Portal-owned: this repository is the
 * table's only writer — `send()` driven by 5.6's audited send Command, `saveDraft()` for a draft —
 * and no `Sync/` handler ever writes it. One row per `(unit_id, week_start)` — the UNIQUE key — so
 * every write below creates that row if absent or updates it in place, never adding a second.
 *
 * Storage-shaped by design: `$kpis` goes in and comes back (`WeeklyReport::$kpis`) as the plain
 * array that is stored — the six KPIs with numerators/denominators and deltas. Mapping 5.1's typed
 * result to and from it, and computing `$contentDigest`, belong to the caller.
 *
 * A sent report is frozen (AD-12): any write to a sent unit-week throws
 * `WeeklyReportAlreadySentException` and leaves the row untouched.
 *
 * Every `$weekStart` must be a Saturday 00:00 UTC (AD-11), given in any timezone (it is compared
 * as an instant); anything else throws `InvalidArgumentException` rather than keying a report to
 * a non-week.
 */
interface WeeklyReportRepository
{
    /**
     * The unit's report for the week, or null when none has been saved — a read that never
     * creates one.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function find(int $unitId, CarbonInterface $weekStart): ?WeeklyReport;

    /**
     * Whether the unit's report for the week has been **really** sent: status sent and not a
     * generated demo report (`is_demo`, #115). A sent report freezes its week's facts (AD-12), so
     * the rollcall of that unit-week is locked from then on; a draft, no report at all, or a demo
     * report leaves it open — the demo design relies on a leader marking over a demo week to make
     * it real. A read that never creates a row.
     *
     * Takes a shared lock on the unit-week's row (or, when there is none yet, on the gap its key
     * would go into), so called inside a write transaction it holds until that transaction ends: a
     * concurrent `send()` — which locks the same row for update before flipping it to sent — waits
     * for the write to commit, and a write that starts after a send waits for the send and then
     * sees it. A save and a send of one unit-week therefore never interleave.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function isSentFor(int $unitId, CarbonInterface $weekStart): bool;

    /**
     * The week's reports — draft or sent — of every unit in `$unitIds` that has one, keyed by
     * `unit_id`, in one query: a roll-up reads all its children's reports at once (5.7, AD-13).
     * A unit with no report is simply absent. Never creates a row.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, WeeklyReport>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function forUnits(array $unitIds, CarbonInterface $weekStart): Collection;

    /**
     * The unit's **sent** reports for weeks before `$weekStart`, newest first, at most `$limit`,
     * each with its `sender` loaded — the report screen's history, trend and delta baseline
     * (5.4). Never creates a row.
     *
     * @return Collection<int, WeeklyReport>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function sentBefore(int $unitId, CarbonInterface $weekStart, int $limit): Collection;

    /**
     * The **sent** reports of any of `$unitIds` for the weeks `$firstWeek` to `$lastWeek`
     * inclusive, oldest week first — the dashboard's sparkline history (5.9). Drafts are never
     * history. One query however many units. Never creates a row.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, WeeklyReport>
     *
     * @throws InvalidArgumentException when either week is not a Saturday 00:00 UTC.
     */
    public function sentWithin(array $unitIds, CarbonInterface $firstWeek, CarbonInterface $lastWeek): Collection;

    /**
     * Save the unit-week's draft — `kpis`, `notes`, the training subject and the unaccounted
     * troopers' reasons as given — creating the row if absent.
     *
     * @param  array<string, mixed>  $kpis
     * @param  list<array{trooper_id: int, reason: string}>  $unaccounted
     *
     * @throws InvalidArgumentException when `$unitId` is not a `units` row, or `$weekStart` is
     *                                  not a Saturday 00:00 UTC.
     * @throws WeeklyReportAlreadySentException when the unit-week is already sent.
     */
    public function saveDraft(
        int $unitId,
        CarbonInterface $weekStart,
        array $kpis,
        ?string $notes,
        ?string $trainingSubject = null,
        array $unaccounted = [],
    ): WeeklyReport;

    /**
     * Freeze the unit-week: write the final `kpis`/`notes`/training subject/unaccounted snapshot, flip `status` to sent and
     * record `sent_at` (now), `sent_by`, `content_digest` and the template version resolved at
     * send — creating the row if no draft was saved, promoting the draft in place otherwise.
     * `$templateVersionId` is a `template_versions` row or null; the send path passes null until
     * the weekly report has a template of its own (see `SendWeeklyReportHandler`).
     *
     * @param  array<string, mixed>  $kpis
     * @param  list<array{trooper_id: int, reason: string}>  $unaccounted
     *
     * @throws InvalidArgumentException when `$unitId` is not a `units` row, `$sentBy` is not a
     *                                  `users` row, `$templateVersionId` is not a
     *                                  `template_versions` row, or `$weekStart` is not a Saturday
     *                                  00:00 UTC.
     * @throws WeeklyReportAlreadySentException when the unit-week is already sent.
     */
    public function send(
        int $unitId,
        CarbonInterface $weekStart,
        array $kpis,
        ?string $notes,
        int $sentBy,
        string $contentDigest,
        ?int $templateVersionId,
        ?string $trainingSubject = null,
        array $unaccounted = [],
    ): WeeklyReport;

    /**
     * Whether `$unit` has any report, draft or sent, in any week — `weekly_reports.unit_id`
     * restricts deletes, so `DeleteUnit` checks this first to refuse with a reason instead of a
     * raw constraint error.
     */
    public function hasReportsFor(Unit $unit): bool;
}
