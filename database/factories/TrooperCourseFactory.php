<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseStatus;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrooperCourse>
 */
class TrooperCourseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trooper_id' => Trooper::factory(),
            'course_id' => Course::factory(),
            'status' => CourseStatus::Required,
            'awarded_at' => null,
            'updated_by' => null,
        ];
    }

    public function qualified(?string $awardedAt = '2026-09-01'): static
    {
        return $this->state(fn () => ['status' => CourseStatus::Qualified, 'awarded_at' => $awardedAt]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => CourseStatus::InProgress]);
    }
}
