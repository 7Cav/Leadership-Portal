<?php

namespace App\Sync;

use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;

/**
 * Builds the {@see RosterSyncScope} for a run. Split in two because the unit must be looked up before the run is
 * opened (a deleted unit is not a failed run), while who the echelon covers needs the fetched roster.
 */
class RosterSyncScopeFactory
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
    ) {}

    /**
     * The sections of the unit's subtree.
     *
     * @return list<int>
     *
     * @throws UnitNoLongerExistsException
     */
    public function sectionIdsOf(int $unitId): array
    {
        $unit = $this->units->find($unitId) ?? throw UnitNoLongerExistsException::withId($unitId);

        return $this->units->subtreeIds($unit)->values()->all();
    }

    /**
     * @param  list<int>|null  $sectionIds  from {@see sectionIdsOf()}, or null for the whole roster
     * @param  array<int, int>  $sections  cav_id => section unit id, resolved from the fetched billets
     */
    public function build(?array $sectionIds, array $sections): RosterSyncScope
    {
        if ($sectionIds === null) {
            return RosterSyncScope::wholeRoster();
        }

        $sectionLookup = array_flip($sectionIds);
        $arrivals = array_keys(array_filter($sections, fn (int $sectionId) => isset($sectionLookup[$sectionId])));

        return RosterSyncScope::echelon(
            $sectionIds,
            [...$this->troopers->cavIdsPlacedIn($sectionIds), ...$arrivals],
        );
    }
}
