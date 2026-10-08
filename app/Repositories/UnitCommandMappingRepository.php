<?php

namespace App\Repositories;

use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sole DB access for the leadership billet → commanded unit mapping (AD-2, 1.16). The org-structure
 * import (2.9) is its only writer and only ever adds rows; the roster-derived half of the user's
 * scope (AD-7, AD-8) reads it through {@see commandedUnitFor()}.
 */
interface UnitCommandMappingRepository
{
    /**
     * Every mapping as `cav_position_id => unit_id`. The import never repoints an existing row,
     * so it uses this to skip mapped billets and to count the ones that now disagree with Cav.
     *
     * @return Collection<int, int>
     */
    public function unitIdsByPositionId(): Collection;

    public function create(int $unitId, int $cavPositionId): UnitCommandMapping;

    /**
     * The unit `$user`'s synced primary billet commands, or null — one joined, indexed query:
     * `users.cav_id` → `troopers.cav_id` (unique) → `troopers.cav_position_id` →
     * `unit_command_mappings.cav_position_id` (unique) → `units`. Null when the user has no
     * `cav_id`, no synced trooper, a billet with no command mapping, or the trooper has departed
     * (`inactive`) — a departed trooper's last billet confers nothing even while it stays mapped.
     */
    public function commandedUnitFor(User $user): ?Unit;
}
