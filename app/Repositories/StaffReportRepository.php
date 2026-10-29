<?php

namespace App\Repositories;

use App\Models\StaffReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `staff_reports` (AD-2): the weekly staff report a platoon or company HQ staff member sends about
 * themselves (policy, 2026-10-29). A row exists only once sent, and is never changed after. The one writer is the
 * audited `SendStaffReport` Command, through {@see send()}.
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
     * @param  list<array{section_id: int, section: ?string}>  $practices
     */
    public function send(int $trooperId, ?int $unitId, CarbonInterface $weekStart, bool $attended, array $practices, int $sentBy): ?StaffReport;
}
