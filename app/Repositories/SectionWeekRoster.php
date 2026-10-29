<?php

namespace App\Repositories;

use App\Models\Trooper;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Who is on a section's rollcall for one Cav week, from {@see TrooperRepository::onRollcall()}. For the running week
 * it is who is placed in the section now. For a week that has ended (`asPlaced`) it is who the placement history puts
 * in it when that week ended (the 2026-10-29 audit), so a trooper who moved or left since is still listed, and
 * `joined` names the ones placed in it partway through that week, after another section.
 */
final readonly class SectionWeekRoster
{
    /**
     * @param  EloquentCollection<int, Trooper>  $troopers  in id order
     * @param  list<int>  $joined  trooper ids
     */
    public function __construct(
        public EloquentCollection $troopers,
        public bool $asPlaced = false,
        public array $joined = [],
    ) {}
}
