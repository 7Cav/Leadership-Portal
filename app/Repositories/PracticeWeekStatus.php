<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;

/**
 * Where one section's practice row stands for one week, for the rollcall's week navigation: how
 * many of the troopers currently placed in the section carry a practice mark (LOA included, as on
 * the rollcall screen), and whether its rollcall is marked posted with no mark changed since.
 */
final readonly class PracticeWeekStatus
{
    public function __construct(
        public CarbonImmutable $weekStart,
        public int $marked,
        public bool $posted,
    ) {}
}
