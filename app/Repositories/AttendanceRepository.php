<?php

namespace App\Repositories;

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use Illuminate\Support\Collection;

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
}
