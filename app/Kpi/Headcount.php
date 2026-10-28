<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\UnitKind;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

/**
 * The headcount (decided 2026-10-24, shown beside strength on Home and the report in #424): everyone serving placed
 * anywhere under a unit, HQ staff and groups included, New Recruits only on their own group. It is the number the org
 * page and roster show as strength, defined here once.
 *
 * It is not a KPI population. The six KPIs (AD-21) are over the troopers in sections, the people on a rollcall, and
 * stay the sum of the sections; this is a count beside them that no rate, at-risk flag or roll-up reads, so it never
 * has to equal a sum of children (a platoon's HQ staff are in the platoon's headcount and in no section's).
 */
final class Headcount
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
    ) {}

    /** The headcount of `$unit`'s subtree, itself included. */
    public function forUnit(Unit $unit): int
    {
        return $this->perUnit($this->units->subtree($unit), $this->troopers->countsBySection())->get($unit->id, 0);
    }

    /**
     * Every unit of a subtree's headcount at once, for a page that shows them all (the roster).
     *
     * @param  Collection<int, Unit>  $subtree  a unit and everything under it
     * @param  Collection<int, int>  $servingBySection  serving troopers placed in each unit, by unit id
     * @return Collection<int, int> headcount by unit id
     */
    public function perUnit(Collection $subtree, Collection $servingBySection): Collection
    {
        return $subtree->mapWithKeys(fn (Unit $unit) => [
            $unit->id => $subtree
                ->filter(fn (Unit $placed) => str_starts_with($placed->path, $unit->path)
                    && ($placed->kind !== UnitKind::Recruit || $unit->kind === UnitKind::Recruit))
                ->sum(fn (Unit $placed) => $servingBySection->get($placed->id, 0)),
        ]);
    }
}
