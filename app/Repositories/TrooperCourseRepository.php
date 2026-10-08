<?php

namespace App\Repositories;

use App\Models\CourseStatus;
use App\Models\TrooperCourse;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sole DB access for `trooper_courses` (AD-2, AD-22). Its one writer is the audited `SetTrooperCourseStatus`
 * Command; every read returns models with their `course` already loaded, so nothing lazy-loads out of here.
 */
interface TrooperCourseRepository
{
    /**
     * Every status row for a trooper, each with its course, in the catalog's order (`sort_order`, then `code`,
     * the same as `CourseRepository::all()`) so a milpac panel never shuffles between requests.
     *
     * @return Collection<int, TrooperCourse>
     */
    public function forTrooper(int $trooperId): Collection;

    /**
     * The trooper's row for one course, with the course loaded, or null when they have none (which reads the
     * same as `none`).
     */
    public function find(int $trooperId, int $courseId): ?TrooperCourse;

    /**
     * Insert the row for a trooper and course. A second row for the same pair is rejected by the database's
     * UNIQUE constraint, not folded into an update, and a trooper or course that does not exist by its foreign
     * key.
     */
    public function create(int $trooperId, int $courseId, CourseStatus $status, ?CarbonInterface $awardedAt, int $updatedBy): TrooperCourse;

    /**
     * Change an existing row's status, award date and who set it.
     */
    public function update(TrooperCourse $row, CourseStatus $status, ?CarbonInterface $awardedAt, int $updatedBy): TrooperCourse;
}
