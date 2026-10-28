<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\UnitKind;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The headcount (decided 2026-10-24, shown beside strength on Home and the report in #424): everyone serving placed
 * anywhere under a unit, HQ staff and groups included, New Recruits only on their own group. It is the number the org
 * page and roster show as strength, defined here once.
 *
 * It is not a KPI population. The six KPIs (AD-21) are over the troopers in sections, the people on a rollcall, and
 * stay the sum of the sections; this is a count beside them that no rate, at-risk flag or roll-up reads, so it never
 * has to equal a sum of children (a platoon's HQ staff are in the platoon's headcount and in no section's).
 *
 * It is also what "has troopers" means (#442): a unit has troopers when its headcount is above zero. A line unit with
 * none is left out of the report chain, the roster and Home ({@see withoutEmptyLineUnits()}) until someone is placed
 * in it or under it. A trooper on leave is still serving and still placed, so a unit whose only members are on LOA has
 * troopers. For a report's week, a unit also counts when someone was placed in it or under it during that week, so a
 * section that sent and was emptied afterwards still rolls up into its platoon for the week it reported.
 */
final class Headcount
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly TrooperPlacementRepository $placements,
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
                ->filter(fn (Unit $placed) => self::counts($placed, $unit))
                ->sum(fn (Unit $placed) => $servingBySection->get($placed->id, 0)),
        ]);
    }

    /**
     * `$units` without the line units that have no troopers (#442): nobody serving placed in them or anywhere under
     * them. Groups are kept whatever their headcount, as is every unit's order. The one test every screen that leaves
     * empty units out applies (the report chain, the roster, Home), so a unit comes back on all of them at once when a
     * trooper is placed in it, by the roster sync or by hand. The org page lists every unit and does not use it.
     *
     * With `$weekStart`, a unit someone was placed in or under during that week counts too (a third query): the
     * report chain asks for the week it reports, so a unit emptied after the week still counts for it. Without it,
     * only who is placed now.
     *
     * `$keep` are unit ids kept whatever their headcount: the report chain passes the units that sent that week's
     * report, whose numbers belong to the week however empty they are now.
     *
     * Two queries whatever is passed (three with a week): the serving troopers by unit, and the tree to place them in.
     *
     * @template TCollection of Collection<int, Unit>
     *
     * @param  TCollection  $units
     * @param  list<int>  $keep
     * @return TCollection
     */
    public function withoutEmptyLineUnits(Collection $units, ?CarbonInterface $weekStart = null, array $keep = []): Collection
    {
        $keep = array_flip($keep);
        $servingBySection = $this->troopers->countsBySection();
        $duringWeek = $weekStart === null ? [] : array_flip($this->placements->unitIdsPlacedDuring($weekStart));
        $placed = $this->units->tree()->filter(fn (Unit $unit) => $servingBySection->get($unit->id, 0) > 0 || isset($duringWeek[$unit->id]));

        return $units
            ->filter(fn (Unit $unit) => ! $unit->isLine() || isset($keep[$unit->id]) || $placed->contains(fn (Unit $holder) => self::counts($holder, $unit)))
            ->values();
    }

    /** Whether troopers placed in `$placed` count towards `$unit`: it is `$unit` or under it, and New Recruits only count on their own group. */
    private static function counts(Unit $placed, Unit $unit): bool
    {
        return str_starts_with($placed->path, $unit->path)
            && ($placed->kind !== UnitKind::Recruit || $unit->kind === UnitKind::Recruit);
    }
}
