<?php

namespace App\Repositories;

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
     * Whether the unit's report for the week has been sent, and so frozen (AD-12). A unit-week with no report, or only a
     * draft, is not sent; nor is one holding only a generated demo report (#115, `is_demo`), which is history for show
     * rather than a record anyone froze, and which a leader's real marks are meant to replace. Never creates a row.
     *
     * A locking read, for a writer whose change must not land after the freeze (the rollcall's marks, Story 3.8): it
     * share-locks the unit-week's row, or on MySQL the gap where that row would go when there is none. Inside the
     * caller's transaction that makes it a current read rather than a snapshot one, so a send committed a moment ago is
     * seen; and a send still to come waits for the caller to commit, since `send()` takes that row exclusively (its
     * upsert, then FOR UPDATE). Either the caller sees the week sent and refuses, or its write commits before the send
     * does. Outside a transaction it is an ordinary read.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function isSent(int $unitId, CarbonInterface $weekStart): bool;

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
     * Save the unit-week's draft — `kpis` and `notes` as given — creating the row if absent.
     *
     * @param  array<string, mixed>  $kpis
     *
     * @throws InvalidArgumentException when `$unitId` is not a `units` row, or `$weekStart` is
     *                                  not a Saturday 00:00 UTC.
     * @throws WeeklyReportAlreadySentException when the unit-week is already sent.
     */
    public function saveDraft(int $unitId, CarbonInterface $weekStart, array $kpis, ?string $notes): WeeklyReport;

    /**
     * Freeze the unit-week: write the final `kpis`/`notes` snapshot, flip `status` to sent and
     * record `sent_at` (now), `sent_by`, `content_digest` and the template version resolved at
     * send — creating the row if no draft was saved, promoting the draft in place otherwise.
     * `$templateVersionId` is null until template versioning (3.5) lands.
     *
     * @param  array<string, mixed>  $kpis
     *
     * @throws InvalidArgumentException when `$unitId` is not a `units` row, `$sentBy` is not a
     *                                  `users` row, or `$weekStart` is not a Saturday 00:00 UTC.
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
    ): WeeklyReport;
}
