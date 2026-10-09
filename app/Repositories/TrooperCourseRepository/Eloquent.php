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
            ->with('course')
            ->join('courses', 'courses.id', '=', 'trooper_courses.course_id')
            ->whereIn('trooper_courses.trooper_id', $trooperIds)
            ->orderBy('courses.sort_order')
            ->orderBy('courses.code')
            ->orderBy('trooper_courses.trooper_id')
            ->select('trooper_courses.*')
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

    public function create(int $trooperId, int $courseId, CourseStatus $status, ?CarbonInterface $awardedAt, int $updatedBy): TrooperCourse
    {
        $row = TrooperCourse::create([
            'trooper_id' => $trooperId,
            'course_id' => $courseId,
            'status' => $status,
            'awarded_at' => $awardedAt,
            'updated_by' => $updatedBy,
        ]);

        return $row->load('course');
    }

    public function update(TrooperCourse $row, CourseStatus $status, ?CarbonInterface $awardedAt, int $updatedBy): TrooperCourse
    {
        $row->fill(['status' => $status, 'awarded_at' => $awardedAt, 'updated_by' => $updatedBy])->save();

        return $row;
    }
}
