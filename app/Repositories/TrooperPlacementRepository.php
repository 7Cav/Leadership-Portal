<?php

namespace App\Repositories;

use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperPlacement;
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
}
