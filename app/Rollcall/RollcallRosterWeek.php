<?php

namespace App\Rollcall;

use App\Models\Attendance;
use App\Models\SectionPractice;
use Illuminate\Support\Collection;

/**
 * One section's week as {@see RollcallRoster::forWeek()} read it: the practice (null before anything was marked), its
 * marks keyed by trooper id, the trooper rows the rollcall page and its forum post read, and whether last week has
 * practice marks to pre-fill from.
 */
final readonly class RollcallRosterWeek
{
    /**
     * @param  Collection<int, Attendance>  $marks
     * @param  list<array<string, mixed>>  $troopers
     */
    public function __construct(
        public ?SectionPractice $practice,
        public Collection $marks,
        public array $troopers,
        public bool $priorWeekMarked,
    ) {}
}
