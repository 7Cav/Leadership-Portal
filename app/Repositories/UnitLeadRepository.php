<?php

namespace App\Repositories;

use Illuminate\Support\Collection;

/**
 * Who leads each unit, for the org page and the roster (decided 2026-10-25): one definition so the two never
 * disagree about a lead or a vacancy. See {@see UnitLead} for the three sources and their precedence.
 */
interface UnitLeadRepository
{
    /**
     * The current lead of each of `$unitIds` that has one, keyed by unit id. A fixed handful of queries.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, UnitLead>
     */
    public function leadsOf(array $unitIds): Collection;

    /**
     * Every unit that should have a lead, held or not: a command billet maps to it, a staff portfolio names its
     * officer's billet, it has an acting-lead row, or it is a line platoon (a platoon always has a Platoon Leader,
     * from the roster or acting). A unit here with no {@see leadsOf()} entry shows "Lead: vacant", with no record of
     * a previous holder.
     *
     * @return Collection<int, int>
     */
    public function leadableUnitIds(): Collection;
}
