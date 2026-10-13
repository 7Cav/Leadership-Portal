<?php

namespace App\Sync;

use App\Integration\Cav\Data\CavRosterEntry;

/**
 * How much of the cached roster one sync run may write: all of it, or one echelon's subtree. The run asks the scope
 * to cut each of its inputs down, so the handler has one code path for both and no "is this scoped?" branches.
 *
 * An echelon covers the troopers already placed in its sections plus anyone whose billet now resolves into them (a
 * transfer in). A trooper moved out by a billet change stays covered, so the move is written through.
 */
final class RosterSyncScope
{
    /**
     * @param  list<int>|null  $sectionIds  the sections departures are confined to; null for the whole roster
     * @param  array<int, true>|null  $coveredCavIds  the troopers the run may write, keyed by `cav_id`; null for everyone
     */
    private function __construct(
        public readonly ?array $sectionIds,
        private readonly ?array $coveredCavIds,
    ) {}

    public static function wholeRoster(): self
    {
        return new self(null, null);
    }

    /**
     * @param  list<int>  $sectionIds  the echelon's subtree
     * @param  list<int>  $coveredCavIds
     */
    public static function echelon(array $sectionIds, array $coveredCavIds): self
    {
        return new self($sectionIds, array_fill_keys($coveredCavIds, true));
    }

    /**
     * @param  list<CavRosterEntry>  $entries
     * @return list<CavRosterEntry>
     */
    public function filterEntries(array $entries): array
    {
        return $this->coveredCavIds === null
            ? $entries
            : array_values(array_filter($entries, fn (CavRosterEntry $entry) => isset($this->coveredCavIds[$entry->cavId])));
    }

    /**
     * @param  list<int>  $cavIds
     * @return list<int>
     */
    public function filterCavIds(array $cavIds): array
    {
        return $this->coveredCavIds === null
            ? $cavIds
            : array_values(array_filter($cavIds, fn (int $cavId) => isset($this->coveredCavIds[$cavId])));
    }

    /**
     * @param  array<int, int>  $sections  cav_id => section unit id
     * @return array<int, int>
     */
    public function filterSections(array $sections): array
    {
        return $this->coveredCavIds === null ? $sections : array_intersect_key($sections, $this->coveredCavIds);
    }
}
