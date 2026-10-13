<?php

namespace App\Commands\Courses;

use App\Models\AreaOfOperation;
use App\Models\Course;
use App\Models\User;
use App\Repositories\CourseRepository;
use App\Support\Audit\AuditEnvelope;
use InvalidArgumentException;

/**
 * Add a course to the portal-owned catalog (AD-22). The one writer of `courses`: the seeder and
 * any future settings screen both come through here, so every course is audited (AD-16). Admin
 * only, checked here against the real flag (AD-6) before anything is written, so the catalog stays
 * closed whatever a caller forgot to gate. No reason is required — only personnel actions and
 * report sends need one.
 *
 * A `code` already in the catalog surfaces the database's unique-constraint error and rolls the
 * audit entry back with it, rather than being coalesced into an update.
 */
class CreateCourse
{
    /** The `courses.code` and `courses.name` columns are plain strings (255). */
    public const MAX_CODE_LENGTH = 255;

    public const MAX_NAME_LENGTH = 255;

    public function __construct(
        private readonly CourseRepository $courses,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * `$sortOrder` is required, not defaulted: a default would sort a new course ahead of the whole
     * catalog. `$code` is trimmed and upper-cased first, because MySQL's UNIQUE index ignores case
     * while SQLite's does not — normalizing here makes "what counts as a duplicate" the same on
     * both, and keeps matrix column headers uniform.
     *
     * @throws CourseWriteForbiddenException when the actor is not an Admin.
     * @throws InvalidArgumentException when the code or name is empty or too long, or the sort order is negative.
     */
    public function handle(
        User $actor,
        string $code,
        string $name,
        AreaOfOperation $category,
        int $sortOrder,
        ?string $description = null,
    ): Course {
        if (! $actor->is_admin) {
            throw CourseWriteForbiddenException::createCourse();
        }

        $code = strtoupper(trim($code));
        $name = trim($name);
        $description = $description === null ? null : trim($description);

        if ($code === '' || mb_strlen($code) > self::MAX_CODE_LENGTH) {
            throw new InvalidArgumentException('A course code must be 1 to '.self::MAX_CODE_LENGTH.' characters.');
        }

        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('A course name must be 1 to '.self::MAX_NAME_LENGTH.' characters.');
        }

        if ($sortOrder < 0) {
            throw new InvalidArgumentException('A course sort order cannot be negative.');
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'course.created',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->courses->create($code, $name, $category, $description, $sortOrder),
        );
    }
}
