<?php

namespace App\Commands\Courses;

use App\Models\AreaOfOperation;
use App\Models\Course;
use App\Models\User;
use App\Repositories\CourseRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Add a course to the portal-owned catalog (AD-22). The one writer of `courses`: the seeder and
 * any future settings screen both come through here, so every course is audited (AD-16). The
 * Admin gate belongs to the caller (AD-6), as for every other write. No reason is required —
 * only personnel actions and report sends need one.
 *
 * A `code` already in the catalog surfaces the database's unique-constraint error and rolls the
 * audit entry back with it, rather than being coalesced into an update.
 */
class CreateCourse
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(
        User $actor,
        string $code,
        string $name,
        AreaOfOperation $category,
        ?string $description = null,
        int $sortOrder = 0,
    ): Course {
        return $this->audit->record(
            actorId: $actor->id,
            action: 'course.created',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->courses->create($code, $name, $category, $description, $sortOrder),
        );
    }
}
