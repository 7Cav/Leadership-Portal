<?php

namespace App\Repositories\CourseRepository;

use App\Models\AreaOfOperation;
use App\Models\Course;
use App\Repositories\CourseRepository;
use Illuminate\Database\Eloquent\Collection;

class Eloquent implements CourseRepository
{
    public function all(): Collection
    {
        $position = array_flip(array_column(AreaOfOperation::cases(), 'value'));

        // Grouped here, in one place, so a course added later cannot interleave areas: AO in enum
        // order first, then `sort_order`, then `code`.
        return Course::query()->get()->sortBy([
            fn (Course $a, Course $b) => $position[$a->category->value] <=> $position[$b->category->value],
            ['sort_order', 'asc'],
            ['code', 'asc'],
        ])->values();
    }

    public function findByCode(string $code): ?Course
    {
        return Course::query()->where('code', $code)->first();
    }

    public function create(string $code, string $name, AreaOfOperation $category, ?string $description, int $sortOrder): Course
    {
        return Course::create([
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'description' => $description,
            'sort_order' => $sortOrder,
        ]);
    }
}
