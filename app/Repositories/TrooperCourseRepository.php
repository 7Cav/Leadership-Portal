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
     * Set a trooper's status on a course in one atomic upsert on the `(trooper_id, course_id)` pair, so two
     * writers racing on a first-time set cannot trip the UNIQUE constraint: the database arbitrates and the
     * last one wins. A trooper or course that does not exist is refused by its foreign key; the Command checks
     * existence first so a caller never sees that driver error. Returns the stored row with its course.
     */
    public function set(int $trooperId, int $courseId, CourseStatus $status, ?CarbonInterface $awardedAt, int $updatedBy): TrooperCourse;
}
