<?php

namespace App\Commands\Units;

use App\Models\UnitKind;
use App\Models\UnitType;

/**
 * A node off the line the org import creates: a regimental staff portfolio, a
 * staff department, or a holding group (ELOA, Reservists, New Recruits). Like {@see CavDesignation}, its callsign is
 * its identity across imports, matched on type + callsign, so a re-run finds the node it made and never duplicates it,
 * and an Admin's rename or move of it survives every later import.
 *
 * Every rule about which groups exist, what they are called and where a new one goes lives in {@see StaffBillet}; this
 * is only the value it hands the import.
 */
final readonly class GroupDesignation
{
    /**
     * @param  string|null  $parentCallsign  the portfolio a new node is created under; null for the regiment
     */
    public function __construct(
        public string $callsign,
        public string $name,
        public UnitKind $kind,
        public ?string $parentCallsign = null,
    ) {}

    public function key(): string
    {
        return CavDesignation::keyFor(UnitType::Group, $this->callsign);
    }

    /**
     * The key of the node a new group is created under: its portfolio, else the regiment.
     */
    public function parentKey(): string
    {
        return $this->parentCallsign === null
            ? CavDesignation::regiment()->key()
            : CavDesignation::keyFor(UnitType::Group, $this->parentCallsign);
    }
}
