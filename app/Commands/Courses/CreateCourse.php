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

    /**
     * `$sortOrder` is required, not defaulted: a default would sort a new course ahead of the whole
     * catalog. `$code` is trimmed and upper-cased first, because MySQL's UNIQUE index ignores case
     * while SQLite's does not — normalizing here makes "what counts as a duplicate" the same on
     * both, and keeps matrix column headers uniform.
     */
    public function handle(
        User $actor,
        string $code,
        string $name,
        AreaOfOperation $category,
        int $sortOrder,
        ?string $description = null,
    ): Course {
        $code = strtoupper(trim($code));

        return $this->audit->record(
            actorId: $actor->id,
            action: 'course.created',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->courses->create($code, trim($name), $category, $description, $sortOrder),
        );
    }
}
