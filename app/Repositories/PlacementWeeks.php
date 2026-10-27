<?php

namespace App\Repositories;

/**
 * Where a set of troopers was placed, week by week (2026-10-27), from {@see TrooperPlacementRepository::sectionsByWeek()}.
 * `current` holds every trooper asked about who is placed now, with their current `primary_section_id`, in trooper id
 * order; `weeks` holds, per trooper, the `week_start` dates they were in a section, each with the section ids (more
 * than one only in a week they moved, in the order they were placed there).
 */
final readonly class PlacementWeeks
{
    /**
     * @param  array<int, int>  $current  trooper id => current `primary_section_id`
     * @param  array<int, array<string, list<int>>>  $weeks  trooper id => `week_start` date => section ids
     */
    public function __construct(
        public array $current = [],
        public array $weeks = [],
    ) {}

    /**
     * The `week_start` dates `$trooperId` was in a section, oldest first.
     *
     * @return list<string>
     */
    public function weeksOf(int $trooperId): array
    {
        $weeks = array_keys($this->weeks[$trooperId] ?? []);
        sort($weeks);

        return $weeks;
    }

    /**
     * Every section any of the troopers was in during the span.
     *
     * @return list<int>
     */
    public function sectionIds(): array
    {
        $ids = [];

        foreach ($this->weeks as $weeks) {
            foreach ($weeks as $sections) {
                foreach ($sections as $id) {
                    $ids[$id] = $id;
                }
            }
        }

        ksort($ids);

        return array_values($ids);
    }
}
