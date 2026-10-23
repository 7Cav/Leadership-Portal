<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;

/**
 * Where one section's week stands, counted over the troopers currently assigned to it: how many
 * signed the rollcall thread and how many have a Section Practice mark, each counting a trooper whose
 * leave covers the week as done, and whether the practice has been held at all (any trooper carries a non-LOA mark that
 * is not an automatic makeup, the same rule `countingMarks` uses).
 *
 * `onLoa` and `unsigned` count the way the rollcall screen does: a trooper on LOA is marked and is
 * not expected to sign, so `unsigned` is the troopers not on LOA who have not signed.
 * `postedAt` is when the leader marked the week's rollcall posted, if they have.
 */
final readonly class SectionWeekProgress
{
    public function __construct(
        public int $sectionId,
        public int $strength,
        public int $signed,
        public int $marked,
        public bool $held,
        public int $onLoa = 0,
        public int $unsigned = 0,
        public ?CarbonImmutable $postedAt = null,
    ) {}
}
