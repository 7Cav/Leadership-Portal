<?php

namespace App\Repositories;

use App\Models\StaffReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `staff_reports` (AD-2): the weekly staff report a platoon, company or battalion HQ staff member sends about
 * themselves. A row exists only once sent, and changes after only when the staff member edits it before its deadline
 * ({@see edit()}). The one writer is the
 * audited `SendStaffReport` Command, through {@see send()} and {@see edit()}; the queued sheet push only notes when it went out
 * ({@see markPushed()}).
 */
interface StaffReportRepository
{
    /** The trooper's sent staff report for the Cav week opening at `$weekStart`, or null. */
    public function find(int $trooperId, CarbonInterface $weekStart): ?StaffReport;

    /**
     * Whether the trooper sent their staff report for the week, as a locking read (shared): what freezes that week's
     * staff practice entries. Inside a transaction a send in flight is waited for, so an entry never changes beside it.
     */
    public function isSent(int $trooperId, CarbonInterface $weekStart): bool;

    /**
     * The sent staff reports of these troopers for the week, keyed by trooper id.
     *
     * @param  list<int>  $trooperIds
     * @return Collection<int, StaffReport>
     */
    public function forTroopers(array $trooperIds, CarbonInterface $weekStart): Collection;

    /**
     * Write the sent report. Insert-or-find under the UNIQUE key: returns the new row, or null when one was already
     * sent for the trooper and week, which is left as it is.
     *
     * `$fields` holds the rest of what was sent: `practice_type`, `ftx_name`, `ftx_date`, `other_events`, `notes`.
     *
     * @param  list<array{section_id: int, section: ?string}>  $practices
     * @param  array<string, mixed>  $fields
     */
    public function send(int $trooperId, ?int $unitId, CarbonInterface $weekStart, bool $attended, array $practices, int $sentBy, array $fields = []): ?StaffReport;

    /**
     * The trooper's sent report for the week, locked for update until the caller's transaction ends, or null when none
     * was sent: what an edit holds while it sets the week's entries again.
     */
    public function lockSent(int $trooperId, CarbonInterface $weekStart): ?StaffReport;

    /**
     * Rewrite the sent report in place with what was sent again before its deadline: the same arguments as
     * {@see send()}, every field replaced (one left out of `$fields` is cleared), `sent_at` now and `sent_by`. The
     * version it replaces is appended to `revisions`, and `sheet_pushed_at` is cleared so the sheet push runs again and
     * overwrites the row it wrote (`sheet_range`). Returns null when no report was sent for the trooper and week.
     *
     * @param  list<array{section_id: int, section: ?string}>  $practices
     * @param  array<string, mixed>  $fields
     */
    public function edit(int $trooperId, ?int $unitId, CarbonInterface $weekStart, bool $attended, array $practices, int $sentBy, array $fields = []): ?StaffReport;

    /** A sent report by id, or null. */
    public function findById(int $id): ?StaffReport;

    /**
     * Note that the report's row went to the Google Sheet now, and the A1 range it is in (kept from the first push when
     * null), so an edit overwrites that row. Returns the row, or null when it is gone.
     */
    public function markPushed(int $id, ?string $range = null): ?StaffReport;
}
