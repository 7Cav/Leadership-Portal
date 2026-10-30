<?php

namespace App\Repositories;

use App\Models\StaffReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `staff_reports` (AD-2): the weekly staff report a platoon, company or battalion HQ staff member sends about
 * themselves. A row exists only once sent, and is never changed after. The one writer is the
 * audited `SendStaffReport` Command, through {@see send()}; the queued sheet push only notes when it went out
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

    /** A sent report by id, or null. */
    public function findById(int $id): ?StaffReport;

    /** Note that the report's row went to the Google Sheet now. Returns the row, or null when it is gone. */
    public function markPushed(int $id): ?StaffReport;
}
