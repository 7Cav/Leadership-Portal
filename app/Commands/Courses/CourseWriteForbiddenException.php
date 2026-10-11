<?php

namespace App\Commands\Courses;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Only an Admin may write course data (AD-6). Enforced in the handler rather than only by a caller,
 * so the write is refused whatever a controller, seeder or console command offered. An
 * `AuthorizationException`, so any HTTP caller that lets it propagate answers 403.
 */
class CourseWriteForbiddenException extends AuthorizationException
{
    public static function createCourse(): self
    {
        return new self('Only an Admin may add a course to the catalog.');
    }
}
