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
 * none is left out of the roster and Home ({@see withoutEmptyLineUnits()}) until someone is placed in it or under it. A
 * trooper on leave is still serving and still placed, so a unit whose only members are on LOA has troopers.
 *
 * The report chain asks one thing more of a unit above a section (#478, {@see reportingLineUnits()}): that a unit
 * under it reports. A platoon whose only troopers are its HQ has troopers, so the roster and Home show it, but it has no
 * report to roll up, so its company's roll-up neither sums nor waits on it. For a report's week, a section also counts
 * when someone was placed in it during that week or when it sent that week's report, so a section emptied afterwards
 * still rolls up into its platoon for the week it reported.
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
     * them now. Groups are kept whatever their headcount, as is every unit's order. The one test every screen that
     * shows people applies (the roster, Home's unit lists, the pickers), so a unit comes back on all of them at once
     * when a trooper is placed in it, by the roster sync or by hand. The org page lists every unit and does not use it.
     * The report chain asks for more, a unit under it that reports ({@see reportingLineUnits()}).
     *
     * Two queries whatever is passed: the serving troopers by unit, and the tree to place them in.
     *
     * @template TCollection of Collection<int, Unit>
     *
     * @param  TCollection  $units
     * @return TCollection
     */
    public function withoutEmptyLineUnits(Collection $units): Collection
    {
        $placed = $this->placedIn($this->units->tree(), $this->troopers->countsBySection(), []);

        return $units
            ->filter(fn (Unit $unit) => ! $unit->isLine() || $placed->contains(fn (Unit $holder) => self::counts($holder, $unit)))
            ->values();
    }

    /**
     * The tops of a viewer's scope as the shell's scope label shows them, "1/A/1-7 +1" (#426). With several, the empty
     * line units the unit switcher's menu leaves out ({@see withoutEmptyLineUnits()}) are left out here too, so "+N"
     * counts only what the menu lists; the first top stays when every one is empty, so the label is never blank. One top
     * is shown as it is, with no extra read. Plain arrays, as the shared props send them (HandleInertiaRequests).
     *
     * @param  Collection<int, Unit>  $tops
     * @return list<array{id: int, designation: string, name: string}>
     */
    public function scopeLabelTops(Collection $tops): array
    {
        if ($tops->count() > 1) {
            $shown = $this->withoutEmptyLineUnits($tops);
            $tops = $shown->isEmpty() ? $tops->take(1) : $shown;
        }

        return $tops->map(fn (Unit $unit) => [
            'id' => $unit->id,
            'designation' => $unit->callsign ?? $unit->name,
            'name' => $unit->name,
        ])->values()->all();
    }

    /**
     * `$units` without the line units that do not take part in the week's report chain (#478): what a roll-up sums and
     * waits on, and what the navigator draws. A line unit with no line children (a section) takes part when it has
     * troopers in it ({@see withoutEmptyLineUnits()}, placed now or during the week); any other line unit when at least
     * one of its line children takes part, all the way down. Either also takes part when it sent the week's report.
     *
     * So a unit whose only troopers are its HQ (a platoon with its PL and PSG on it and every section empty) has no
     * report to send and leaves its parent's roll-up like an empty unit, while the roster and Home still show it, by
     * {@see withoutEmptyLineUnits()}, since it does have people in it. And a section that sent keeps every unit above it
     * in that week's chain, however empty they are now.
     *
     * `$sent` are the ids of the units that sent the week's report: every one under `$units` that matters, since a
     * unit can take part only through what sent below it. Groups are kept, as is every unit's order. The same queries
     * as {@see withoutEmptyLineUnits()}: the serving troopers by unit, the tree, and with a week who was placed during it.
     *
     * @template TCollection of Collection<int, Unit>
     *
     * @param  TCollection  $units
     * @param  list<int>  $sent
     * @return TCollection
     */
    public function reportingLineUnits(Collection $units, ?CarbonInterface $weekStart = null, array $sent = []): Collection
    {
        $sent = array_flip($sent);
        $servingBySection = $this->troopers->countsBySection();
        $duringWeek = $weekStart === null ? [] : array_flip($this->placements->unitIdsPlacedDuring($weekStart));
        $tree = $this->units->tree();
        $placed = $this->placedIn($tree, $servingBySection, $duringWeek);
        $parents = $tree->filter(fn (Unit $unit) => $unit->isLine())->pluck('parent_id')->filter()->flip();
        $reporting = [];
        $childReports = [];

        // The tree is in depth-first order, every unit after its parent, so walked backwards each unit's children are
        // decided before it is.
        foreach ($tree->reverse() as $unit) {
            if (! $unit->isLine()) {
                continue;
            }

            $reports = isset($sent[$unit->id]) || ($parents->has($unit->id)
                ? isset($childReports[$unit->id])
                : $placed->contains(fn (Unit $holder) => self::counts($holder, $unit)));

            if ($reports) {
                $reporting[$unit->id] = true;

                if ($unit->parent_id !== null) {
                    $childReports[$unit->parent_id] = true;
                }
            }
        }

        return $units
            ->filter(fn (Unit $unit) => ! $unit->isLine() || isset($reporting[$unit->id]))
            ->values();
    }

    /**
     * The units of `$tree` someone serving is placed in now, or (`$duringWeek`, ids as keys) was during the week.
     *
     * @param  Collection<int, Unit>  $tree
     * @param  Collection<int, int>  $servingBySection
     * @param  array<int, int>  $duringWeek
     * @return Collection<int, Unit>
     */
    private function placedIn(Collection $tree, Collection $servingBySection, array $duringWeek): Collection
    {
        return $tree->filter(fn (Unit $unit) => $servingBySection->get($unit->id, 0) > 0 || isset($duringWeek[$unit->id]));
    }

    /** Whether troopers placed in `$placed` count towards `$unit`: it is `$unit` or under it, and New Recruits only count on their own group. */
    private static function counts(Unit $placed, Unit $unit): bool
    {
        return str_starts_with($placed->path, $unit->path)
            && ($placed->kind !== UnitKind::Recruit || $unit->kind === UnitKind::Recruit);
    }
}
