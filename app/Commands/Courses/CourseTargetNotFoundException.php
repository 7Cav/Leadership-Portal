<?php

namespace App\Commands\Courses;

use InvalidArgumentException;

/**
 * The trooper or course an id named is not there. A domain error (an `InvalidArgumentException`, like the
 * command's other refusals) a caller can map to 404 or 422, instead of a foreign-key driver error leaking as a 500.
 */
class CourseTargetNotFoundException extends InvalidArgumentException
{
    public static function trooper(int $id): self
    {
        return new self('That trooper no longer exists. Reload the page.');
    }

    public static function course(int $id): self
    {
        return new self('That course no longer exists. Reload the page.');
    }
}
