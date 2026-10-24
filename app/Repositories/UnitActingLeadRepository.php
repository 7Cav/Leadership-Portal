<?php

namespace App\Repositories;

use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitActingLead;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sole DB access for acting leads (AD-2, decided 2026-10-25): the serving trooper an Admin assigned to lead a unit the
 * Cav roster names no lead for. Written only by the audited acting-lead Commands; read by ScopeResolver and the lead
 * read model. Every read that confers scope or names a lead asks for a serving trooper, so a lead whose holder left
 * every roster stops applying on the next read, with the row kept for an Admin to remove on the record.
 */
interface UnitActingLeadRepository
{
    /**
     * The units `$user`'s trooper (`users.cav_id`) is acting lead of while serving, in id order. Empty for a user with
     * no milpac link or whose trooper no longer serves.
     *
     * @return Collection<int, Unit>
     */
    public function unitsLedBy(User $user): Collection;

    /**
     * The serving acting lead of each of `$unitIds` that has one, keyed by unit id. One query.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, Trooper>
     */
    public function leadsOf(array $unitIds): Collection;

    /**
     * The acting-lead row of `$unitId`, serving or not, with its trooper loaded.
     */
    public function forUnit(int $unitId): ?UnitActingLead;

    /**
     * Every unit id that has an acting-lead row, serving or not.
     *
     * @return Collection<int, int>
     */
    public function unitIds(): Collection;

    /**
     * Make `$trooperId` the acting lead of `$unitId`, replacing none: the unit must have no acting lead (the
     * unique key refuses a second). Returns the row.
     */
    public function assign(int $unitId, int $trooperId, ?int $assignedBy): UnitActingLead;

    /**
     * Remove the unit's acting lead. Returns the removed row, or null when it had none.
     */
    public function remove(int $unitId): ?UnitActingLead;
}
