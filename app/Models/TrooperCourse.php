<?php

namespace App\Models;

use Database\Factories\TrooperCourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One trooper's status on one course (Story 6.2). Portal-owned (AD-22): nothing external authors it, so every
 * column is mass-assignable because the sole writer is `TrooperCourseRepository`, itself only ever reached
 * through the audited `SetTrooperCourseStatus` Command, never from request data directly.
 */
#[Fillable(['trooper_id', 'course_id', 'status', 'awarded_at', 'updated_by'])]
class TrooperCourse extends Model
{
    /** @use HasFactory<TrooperCourseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'trooper_id' => 'integer',
            'course_id' => 'integer',
            'status' => CourseStatus::class,
            'awarded_at' => 'date',
        ];
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
