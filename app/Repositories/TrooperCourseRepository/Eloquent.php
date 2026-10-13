<?php

namespace App\Repositories\TrooperCourseRepository;

use App\Models\CourseStatus;
use App\Models\TrooperCourse;
use App\Repositories\TrooperCourseRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class Eloquent implements TrooperCourseRepository
{
    public function forTrooper(int $trooperId): Collection
    {
        return TrooperCourse::query()
            ->with('course')
            ->join('courses', 'courses.id', '=', 'trooper_courses.course_id')
            ->where('trooper_courses.trooper_id', $trooperId)
            ->orderBy('courses.sort_order')
            ->orderBy('courses.code')
            ->select('trooper_courses.*')
            ->get();
    }

    public function forTroopers(array $trooperIds): Collection
    {
        if ($trooperIds === []) {
            return new Collection;
        }

        return TrooperCourse::query()
            ->whereIn('trooper_id', $trooperIds)
            ->orderBy('trooper_id')
            ->orderBy('course_id')
            ->get();
    }

    public function find(int $trooperId, int $courseId): ?TrooperCourse
    {
        return TrooperCourse::query()
            ->with('course')
            ->where('trooper_id', $trooperId)
            ->where('course_id', $courseId)
            ->first();
    }

    public function set(int $trooperId, int $courseId, CourseStatus $status, ?CarbonInterface $awardedAt, int $updatedBy): TrooperCourse
    {
        TrooperCourse::query()->upsert(
            [[
                'trooper_id' => $trooperId,
                'course_id' => $courseId,
                'status' => $status->value,
                'awarded_at' => $awardedAt?->toDateString(),
                'updated_by' => $updatedBy,
            ]],
            ['trooper_id', 'course_id'],
            ['status', 'awarded_at', 'updated_by'],
        );

        return $this->find($trooperId, $courseId);
    }
}
