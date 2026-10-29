<?php

namespace App\Repositories;

/**
 * Which section's rollcall each trooper is on for one Cav week, for several sections at once, from
 * {@see TrooperRepository::onRollcalls()}. For the running week it is who is placed in them now. For a week that has
 * ended (`asPlaced`) it is who the placement history puts in them when that week ended (the 2026-10-29 audit), so a
 * trooper who moved or left since still counts on the section they ended it in, as {@see SectionWeekRoster} lists them.
 */
final readonly class WeekRollcalls
{
    /**
     * @param  array<int, int>  $sections  trooper id => section id, in trooper id order
     */
    public function __construct(
        public array $sections,
        public bool $asPlaced = false,
    ) {}
}
