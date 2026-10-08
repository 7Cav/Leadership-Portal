<?php

namespace App\Repositories;

use App\Models\SectionPractice;
use Carbon\CarbonInterface;
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
     * section-week both land on one row rather than one of them crashing on a duplicate key.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function findOrCreate(int $sectionId, CarbonInterface $weekStart): SectionPractice;

    /**
     * The section's practice row for the week, or null when none has been recorded — a read that
     * never creates one.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function find(int $sectionId, CarbonInterface $weekStart): ?SectionPractice;
}
