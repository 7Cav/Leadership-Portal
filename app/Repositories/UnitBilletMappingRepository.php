<?php

namespace App\Repositories;

use Illuminate\Support\Collection;

/**
 * Sole DB access for the curated billet → section mapping (AD-2, 2.5). `Sync\SyncRosterHandler`
 * is the only reader today; an Admin maintains the table's rows directly (no writer here yet — a
 * maintenance UI is future work).
 */
interface UnitBilletMappingRepository
{
    /**
     * Every mapping whose target `units` row is `type = section` (AD-18) — a mapping pointing at
     * a platoon/company is excluded here rather than surfaced as resolvable, so a Cav identifier
     * landing on a non-leaf reads as unresolved to every caller, not something each caller must
     * re-check. Keyed by `cav_position_id` so a caller can resolve a trooper's billet straight to
     * a section unit id.
     *
     * @return Collection<int, int>
     */
    public function sectionIdsByPositionId(): Collection;
}
