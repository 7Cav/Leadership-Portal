<?php

namespace Database\Factories;

use App\Models\AreaOfOperation;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???-???')),
            'name' => fake()->unique()->words(3, true),
            'category' => AreaOfOperation::General,
            'description' => null,
            'sort_order' => fake()->numberBetween(0, 1000),
        ];
    }
}
