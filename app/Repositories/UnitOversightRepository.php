<?php

namespace App\Repositories;

use App\Models\Unit;
use App\Models\UnitOversight;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sole DB access for unit oversight (AD-2, policy, 2026-10-29): the regimental staff officer who oversees a
 * unit. Written only by the audited `SetUnitOversightHandler`; read by ScopeResolver (read scope only) and the org page.
 * Every read that confers scope asks for a serving trooper, so oversight whose holder left every roster stops applying on
 * the next read, with the row kept for an Admin to clear on the record.
 */
interface UnitOversightRepository
{
    /**
     * The units `$user`'s trooper (`users.cav_id`) oversees while serving, in id order. Empty for a user with no milpac
     * link or whose trooper no longer serves.
     *
     * @return Collection<int, Unit>
     */
    public function unitsOverseenBy(User $user): Collection;

    /**
     * The units each of `$trooperIds` oversees while serving, grouped by trooper id, each list in unit id order. One
     * query.
     *
     * @param  list<int>  $trooperIds
     * @return Collection<int, Collection<int, Unit>>
     */
    public function unitsOverseenByTroopers(array $trooperIds): Collection;

    /**
     * The oversight row of each of `$unitIds` that has one, serving or not, with its trooper loaded, keyed by unit id.
     * One query.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, UnitOversight>
     */
    public function forUnits(array $unitIds): Collection;

    /** The oversight row of `$unitId`, serving or not, with its trooper loaded. */
    public function forUnit(int $unitId): ?UnitOversight;

    /**
     * Make `$trooperId` the overseer of `$unitId`, replacing whoever was. Returns the row as it now stands.
     */
    public function set(int $unitId, int $trooperId, ?int $setBy, ?int $setByAdmin, ?int $setAsUnit): UnitOversight;

    /** Clear the unit's overseer. Returns the removed row, or null when it had none. */
    public function clear(int $unitId): ?UnitOversight;
}
