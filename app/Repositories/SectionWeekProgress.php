<?php

namespace App\Repositories;

/**
 * Where one section's week stands, counted over the troopers currently assigned to it: how many
 * signed the rollcall thread, how many have a Section Practice mark, and whether the practice has
 * been held at all (any trooper carries a non-LOA mark, the same rule `countingMarks` uses).
 */
final readonly class SectionWeekProgress
{
    public function __construct(
        public int $sectionId,
        public int $strength,
        public int $signed,
        public int $marked,
        public bool $held,
    ) {}
}
