<?php

namespace App\Repositories;

use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sole DB access for the leadership billet → commanded unit mapping (AD-2, 1.16). The org-structure
 * import (2.9) is its only writer and reconciles it to exactly what Cav publishes on every run —
 * unlike the section mapping, there is no hand curation to protect, and a stale row would keep
 * granting scope. The roster-derived half of the user's scope (AD-7, AD-8) reads it through
 * {@see commandedUnitFor()}.
 */
interface UnitCommandMappingRepository
{
    /**
     * Every mapping as `cav_position_id => unit_id`, for the import to diff against Cav.
     *
     * @return Collection<int, int>
     */
    public function unitIdsByPositionId(): Collection;

    public function create(int $unitId, int $cavPositionId): UnitCommandMapping;

    /**
     * Point an existing billet's mapping at a different unit (a Cav reorg).
     */
    public function repoint(int $cavPositionId, int $unitId): void;

    /**
     * Delete every mapping whose billet is not in `$cavPositionIds` — a billet Cav no longer
     * lists as a command must stop conferring scope. Returns the number of rows removed.
     *
     * @param  list<int>  $cavPositionIds
     */
    public function deleteExcept(array $cavPositionIds): int;

    /**
     * The unit `$user`'s synced primary billet commands, or null — one joined, indexed query:
     * `users.cav_id` → `troopers.cav_id` (unique) → `troopers.cav_position_id` →
     * `unit_command_mappings.cav_position_id` (unique) → `units`. Null when the user has no
     * `cav_id`, no synced trooper, or a billet with no command mapping, and unless the trooper
     * stands on a serving roster (combat, reserve, ELOA) — an allow-list, so a departed
     * (`inactive`) trooper's last billet, or any standing added later, confers nothing.
     */
    public function commandedUnitFor(User $user): ?Unit;

    /**
     * {@see commandedUnitFor()} for many users at once, keyed by `cav_id` — one joined query for
     * the view-as picker's people list (Story 1.13), with the same serving-roster allow-list. A
     * cav id with no commanded unit is absent.
     *
     * @param  list<int>  $cavIds
     * @return Collection<int, Unit>
     */
    public function commandedUnitsByCavId(array $cavIds): Collection;
}
