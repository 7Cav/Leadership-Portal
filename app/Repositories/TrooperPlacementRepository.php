<?php

namespace App\Repositories;

use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperPlacement;
use App\Models\Unit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Sole DB access for the placement history (AD-2, decided 2026-10-27): where each trooper was placed, and when.
 * Written by `TrooperRepository` and `UnitRepository` in the same transaction as the change to
 * `troopers.primary_section_id`, never on its own. Read by the KPI facts, so a past week counts against the section
 * the trooper was in that week, and by the milpac and the roster Sheet to show the moves.
 */
interface TrooperPlacementRepository
{
    /**
     * Record placement changes made at `$at`: for each trooper, the unit they leave and the unit they join (either
     * may be null: a first placement, or a trooper left unplaced). The open row is closed at `$at` and a new open row
     * starts there. A trooper with no history yet gets their old placement recorded first, open-ended into the past,
     * so their earlier weeks still read against it. A change to the same unit records nothing.
     *
     * @param  array<int, array{0: ?int, 1: ?int}>  $changes  trooper id => [old unit id, new unit id]
     */
    public function record(array $changes, PlacementSource $source, CarbonInterface $at): void;

    /**
     * Every placement of one trooper, oldest first, each with its unit loaded (null for a unit since deleted).
     *
     * @return Collection<int, TrooperPlacement>
     */
    public function historyOf(int $trooperId): Collection;

    /**
     * The sections each trooper was placed in during each Cav week from `$firstWeek` to `$lastWeek`, with their
     * current placement ({@see PlacementWeeks}). A week they were in no section (not placed yet, or on HQ staff or in
     * a group) is absent. A trooper placed nowhere now is left out.
     *
     * A trooper with no history, or whose open row is not their current `primary_section_id` (a placement written
     * around this repository, such as a seeder), is read as placed in their current unit for every week: the rule
     * from before the history existed. One query, however many troopers.
     *
     * @param  iterable<int>|Builder<Trooper>  $troopers  trooper ids, or a query of the troopers
     *
     * @throws InvalidArgumentException when either bound is not a Saturday 00:00 UTC.
     */
    public function sectionsByWeek(iterable|Builder $troopers, CarbonInterface $firstWeek, CarbonInterface $lastWeek): PlacementWeeks;

    /**
     * {@see sectionsByWeek()} for the troopers who count in `$unit` in the week `$lastWeek` (the 2026-10-29 audit): a
     * past week's roster, kept as it stood when that week ended rather than as it stands now. A trooper counts where
     * the history puts them at the end of the week ({@see PlacementWeeks::sectionIn()}), so a trooper who moved during
     * it is on one section's roster, the one they moved to, and a section anywhere under `$unit` must be that one. They
     * must also have served that week: serving now, or gone with a `departed_at` after the week opened. A trooper who
     * left before that date was recorded never counts in a past week. One query, however many troopers.
     *
     * @throws InvalidArgumentException when either bound is not a Saturday 00:00 UTC.
     */
    public function sectionsByWeekWithin(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek): PlacementWeeks;

    /**
     * {@see sectionsByWeekWithin()} for the sections `$sectionIds` rather than a subtree: the troopers on those sections'
     * rosters for the week `$lastWeek`, each on the one they ended it in. What a page about several sections' rollcalls
     * reads (the rollcall picker, Home's step matrix), so it agrees with each rollcall. One query.
     *
     * @param  list<int>  $sectionIds
     *
     * @throws InvalidArgumentException when either bound is not a Saturday 00:00 UTC.
     */
    public function sectionsByWeekAmong(array $sectionIds, CarbonInterface $firstWeek, CarbonInterface $lastWeek): PlacementWeeks;

    /**
     * The units that had someone in them in each Cav week from `$firstWeek` to `$lastWeek` (#442), by week start
     * (Y-m-d), read as a past week's roster reads them ({@see sectionsByWeekWithin()}, the 2026-10-29 audit), so the
     * report chain waits on the sections whose rollcall lists someone that week: a section when someone ended the week
     * in it ({@see PlacementWeeks::sectionIn()}), and another unit (HQ staff, a group) when someone was in it at any
     * moment of the week. A trooper who left the Cav counts for the weeks that opened before their `departed_at`, and
     * one who left before that date was recorded counts for none.
     *
     * A serving trooper with no history, or whose open row is not their current `primary_section_id`, counts in their
     * current unit for every week (the rule from before the history existed), and their rows are not read. A trooper
     * placed nowhere now with no open row (moved out of every unit) counts through their closed rows, the one case a
     * past roster does not list (it reads only troopers placed now). One query, however many weeks. Each week's ids
     * ascending.
     *
     * @return array<string, list<int>> every week of the span, earliest first
     *
     * @throws InvalidArgumentException when either bound is not a Saturday 00:00 UTC.
     */
    public function unitIdsPlacedByWeek(CarbonInterface $firstWeek, CarbonInterface $lastWeek): array;
}
