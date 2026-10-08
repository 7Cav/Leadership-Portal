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
 * `MarkAttendance` Command is its one creating writer. Business rules — manual-wins-over-auto,
 * LOA-derived status (3.4), who may mark whom — belong to that caller; this layer only persists
 * the row state it is handed.
 */
interface AttendanceRepository
{
    /**
     * Write a trooper's full row state for one practice: an upsert on the `(trooper_id,
     * section_practice_id)` UNIQUE key, so a repeat mark updates the one row rather than adding a
     * second counting mark. `$status` is null while the rollcall is signed but the practice not
     * yet marked.
     */
    public function record(
        SectionPractice $practice,
        int $trooperId,
        AttendanceSource $source,
        ?AttendanceStatus $status,
        RollcallSignature $rollcall,
    ): Attendance;

    /**
     * Every mark against one practice, each with its `trooper` loaded. Empty is a valid answer:
     * a practice row may exist before anyone is marked.
     *
     * @return Collection<int, Attendance>
     */
    public function forPractice(SectionPractice $practice): Collection;
}
