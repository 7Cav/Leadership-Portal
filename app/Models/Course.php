<?php

namespace App\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A course or qualification 7Cav offers. Portal-owned (AD-22): no external source authors it, so
 * every column is mass-assignable because the sole writer is `CourseRepository::create()`, itself
 * only ever reached through the audited `CreateCourse` Command — never from caller-supplied
 * request data directly.
 */
#[Fillable(['code', 'name', 'category', 'description', 'sort_order'])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => AreaOfOperation::class,
            'sort_order' => 'integer',
        ];
    }
}
