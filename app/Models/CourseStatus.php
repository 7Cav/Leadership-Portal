<?php

namespace App\Models;

/**
 * Where one trooper stands on one course (Story 6.2). The single definition: the milpac courses panel (6.4)
 * groups by it and the readiness matrix (6.9) maps each cell from it, so neither keeps its own list. Backed so
 * the stored `trooper_courses.status` and this type stay in lockstep.
 *
 * `None` is a trooper who neither holds the course nor is required to; having no row at all means the same.
 * Case order is the order the milpac groups them in.
 */
enum CourseStatus: string
{
    case Qualified = 'qualified';
    case InProgress = 'in_progress';
    case Required = 'required';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Qualified => 'Qualified',
            self::InProgress => 'In progress',
            self::Required => 'Required',
            self::None => 'None',
        };
    }

    /** The trooper holds the course. Only then does an award date mean anything. */
    public function holds(): bool
    {
        return $this === self::Qualified;
    }
}
