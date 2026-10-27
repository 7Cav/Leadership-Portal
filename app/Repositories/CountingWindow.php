<?php

namespace App\Repositories;

use Illuminate\Support\Collection;

/**
 * What {@see AttendanceRepository::countingWindow()} reads for the KPI service in one pass: the counting marks, every
 * trooper of the population with their current section (whether or not they have a mark), and the weeks each was
 * placed in a section, which is the span their RC is counted over (2026-10-27).
 */
final readonly class CountingWindow
{
    /**
     * @param  Collection<int, CountingMark>  $marks  ordered by trooper id, then week
     * @param  array<int, int>  $sections  trooper id => current section id, in trooper id order
     * @param  PlacementWeeks  $placements  where each was placed, week by week
     */
    public function __construct(
        public Collection $marks,
        public array $sections,
        public PlacementWeeks $placements,
    ) {}
}
