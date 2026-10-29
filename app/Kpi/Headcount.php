<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\UnitKind;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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
 * when that week's rollcall lists someone (they ended the week in it, the 2026-10-29 audit) or when it sent that week's
 * report, so a section emptied afterwards still rolls up into its platoon for the week it reported. A past week is judged only by that: a section created or
 * filled after it is not one that week waits on. Who is placed now counts for the running week.
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
     * shows people applies (the roster, the pickers, the unit switcher), so a unit comes back on all of them at once
     * when a trooper is placed in it, by the roster sync or by hand. The org page lists every unit and does not use it.
     * The report chain asks for more, a unit under it that reports ({@see reportingLineUnits()}).
     *
     * `$keep` are unit ids kept whatever their headcount: the view as list passes the unit being viewed as now, so it
     * stays in the list after it empties.
     *
     * Two queries whatever is passed: the serving troopers by unit, and the tree to place them in.
     *
     * @template TCollection of Collection<int, Unit>
     *
     * @param  TCollection  $units
     * @param  list<int>  $keep
     * @return TCollection
     */
    public function withoutEmptyLineUnits(Collection $units, array $keep = []): Collection
    {
        $keep = array_flip($keep);
        $holders = self::holders($this->units->tree(), $this->placedNow());

        return $units
            ->filter(fn (Unit $unit) => ! $unit->isLine() || isset($keep[$unit->id]) || $holders->contains(fn (Unit $holder) => self::counts($holder, $unit)))
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
     * troopers in it; any other line unit when at least one of its line children takes part, all the way down. Either
     * also takes part when it sent the week's report.
     *
     * So a unit whose only troopers are its HQ (a platoon with its PL and PSG on it and every section empty) has no
     * report to send and leaves its parent's roll-up like an empty unit, while the roster and Home still show it, by
     * {@see withoutEmptyLineUnits()}, since it does have people in it. And a section that sent keeps every unit above it
     * in that week's chain, however empty they are now.
     *
     * With a week, "has troopers" is read for that week ({@see placedByWeek()}): from the placement history, someone
     * ended the week in the section, so is on its rollcall for the week as {@see TrooperRepository::onRollcall()} lists
     * it (a trooper who moved or left after the week included, one who moved on during it not). Who is placed now counts only for the running Cav week, so a section
     * created or filled after a past week is not one that week waits on. With no week, who is placed now.
     *
     * `$sent` are the ids of the units that sent the week's report: every one under `$units` that matters, since a
     * unit can take part only through what sent below it. Groups are kept, as is every unit's order. Two queries (the
     * tree, and who was placed during the week or is placed now), three for the running week (both).
     *
     * @template TCollection of Collection<int, Unit>
     *
     * @param  TCollection  $units
     * @param  list<int>  $sent
     * @return TCollection
     */
    public function reportingLineUnits(Collection $units, ?CarbonInterface $weekStart = null, array $sent = []): Collection
    {
        if ($weekStart === null) {
            $tree = $this->units->tree();
            $reporting = self::reportingIds($tree, self::holders($tree, $this->placedNow()), array_flip($sent));

            return $units->filter(fn (Unit $unit) => ! $unit->isLine() || isset($reporting[$unit->id]))->values();
        }

        $week = WeekOf::requireStart($weekStart);

        return $this->reportingLineUnitsByWeek($units, $week, $week, [$week->toDateString() => $sent])[$week->toDateString()];
    }

    /**
     * {@see reportingLineUnits()} for each Cav week from `$firstWeek` to `$lastWeek`, read at once (Home's sparkline):
     * the same queries as for one week, however many weeks.
     *
     * @template TCollection of Collection<int, Unit>
     *
     * @param  TCollection  $units
     * @param  array<string, list<int>>  $sentByWeek  the units that sent each week's report, by week start (Y-m-d)
     * @return array<string, TCollection> by week start (Y-m-d), earliest first
     *
     * @throws InvalidArgumentException when either bound is not a Saturday 00:00 UTC.
     */
    public function reportingLineUnitsByWeek(Collection $units, CarbonInterface $firstWeek, CarbonInterface $lastWeek, array $sentByWeek = []): array
    {
        $tree = $this->units->tree();
        $result = [];

        foreach ($this->placedByWeek($firstWeek, $lastWeek) as $date => $placed) {
            $reporting = self::reportingIds($tree, self::holders($tree, $placed), array_flip($sentByWeek[$date] ?? []));
            $result[$date] = $units->filter(fn (Unit $unit) => ! $unit->isLine() || isset($reporting[$unit->id]))->values();
        }

        return $result;
    }

    /**
     * `$units` without the line units that neither have troopers now ({@see withoutEmptyLineUnits()}) nor take part in
     * the week's report chain ({@see reportingLineUnits()}): the top units a page about one week's reports starts from
     * (the roll-up workspace, Home). A unit emptied after a week it reported for is still there for that week, and a
     * unit whose only troopers are its HQ is still there, to land on its own report. Three queries.
     *
     * @template TCollection of Collection<int, Unit>
     *
     * @param  TCollection  $units
     * @param  list<int>  $sent  the units that sent the week's report
     * @return TCollection
     */
    public function withTroopersOrReporting(Collection $units, CarbonInterface $weekStart, array $sent): Collection
    {
        $week = WeekOf::requireStart($weekStart);
        $tree = $this->units->tree();
        $now = $this->placedNow();
        $placed = $this->placedByWeek($week, $week, $now)[$week->toDateString()];
        $reporting = self::reportingIds($tree, self::holders($tree, $placed), array_flip($sent));
        $holders = self::holders($tree, $now);

        return $units
            ->filter(fn (Unit $unit) => ! $unit->isLine() || isset($reporting[$unit->id]) || $holders->contains(fn (Unit $holder) => self::counts($holder, $unit)))
            ->values();
    }

    /**
     * The units someone was in during each Cav week from `$firstWeek` to `$lastWeek`, ids as keys, by week start
     * (Y-m-d): the placement history read as a past week's rollcall reads it
     * ({@see TrooperPlacementRepository::unitIdsPlacedByWeek()}), and for the
     * running week (or a later one) also the units someone serving is placed in now. A past week is never judged by
     * who is placed now.
     *
     * @param  array<int, true>|null  $now  the units placed now, when the caller has already read them
     * @return array<string, array<int, true>>
     */
    private function placedByWeek(CarbonInterface $firstWeek, CarbonInterface $lastWeek, ?array $now = null): array
    {
        $first = WeekOf::requireStart($firstWeek);
        $last = WeekOf::requireStart($lastWeek);
        $running = WeekOf::start(CarbonImmutable::now());
        $during = $this->placements->unitIdsPlacedByWeek($first, $last);

        if ($last->greaterThanOrEqualTo($running)) {
            $now ??= $this->placedNow();
        }

        $placed = [];

        for ($week = $first; $week->lessThanOrEqualTo($last); $week = $week->addWeek()) {
            $date = $week->toDateString();
            $placed[$date] = array_fill_keys($during[$date] ?? [], true) + ($week->greaterThanOrEqualTo($running) ? ($now ?? []) : []);
        }

        return $placed;
    }

    /**
     * The units someone serving is placed in now, ids as keys.
     *
     * @return array<int, true>
     */
    private function placedNow(): array
    {
        return $this->troopers->countsBySection()
            ->filter(fn (int $total) => $total > 0)
            ->map(fn () => true)
            ->all();
    }

    /**
     * The units of `$tree` among `$placed` (ids as keys).
     *
     * @param  Collection<int, Unit>  $tree
     * @param  array<int, true>  $placed
     * @return Collection<int, Unit>
     */
    private static function holders(Collection $tree, array $placed): Collection
    {
        return $tree->filter(fn (Unit $unit) => isset($placed[$unit->id]));
    }

    /**
     * The line units of `$tree` that take part in a report chain ({@see reportingLineUnits()}), ids as keys.
     *
     * @param  Collection<int, Unit>  $tree
     * @param  Collection<int, Unit>  $holders  the units someone is placed in
     * @param  array<int, int>  $sent  the units that sent, ids as keys
     * @return array<int, true>
     */
    private static function reportingIds(Collection $tree, Collection $holders, array $sent): array
    {
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
                : $holders->contains(fn (Unit $holder) => self::counts($holder, $unit)));

            if ($reports) {
                $reporting[$unit->id] = true;

                if ($unit->parent_id !== null) {
                    $childReports[$unit->parent_id] = true;
                }
            }
        }

        return $reporting;
    }

    /** Whether troopers placed in `$placed` count towards `$unit`: it is `$unit` or under it, and New Recruits only count on their own group. */
    private static function counts(Unit $placed, Unit $unit): bool
    {
        return str_starts_with($placed->path, $unit->path)
            && ($placed->kind !== UnitKind::Recruit || $unit->kind === UnitKind::Recruit);
    }
}
