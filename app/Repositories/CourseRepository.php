<?php

namespace App\Repositories;

use App\Models\AreaOfOperation;
use App\Models\Course;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sole DB access for the course catalog (AD-2, AD-22).
 */
interface CourseRepository
{
    /**
     * The whole catalog in its one deterministic order: area of operation (the enum's case order),
     * then `sort_order` within it, then `code` for courses that share a `sort_order`, so milpac
     * panels and matrix columns stay grouped and never shuffle between requests. `sort_order` is
     * therefore only meaningful within a category.
     *
     * @return Collection<int, Course>
     */
    public function all(): Collection;

    public function findByCode(string $code): ?Course;

    /**
     * Insert a course. A `code` already in the catalog is rejected by the database's UNIQUE
     * constraint, not coalesced into an update.
     */
    public function create(string $code, string $name, AreaOfOperation $category, ?string $description, int $sortOrder): Course;
}
