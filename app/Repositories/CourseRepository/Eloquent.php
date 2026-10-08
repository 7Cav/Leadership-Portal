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
        return Course::query()->orderBy('sort_order')->orderBy('code')->get();
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
