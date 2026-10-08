<?php

namespace App\Repositories;

use App\Models\UnitBilletMapping;
use Illuminate\Support\Collection;

/**
 * Sole DB access for the curated billet → section mapping (AD-2, 2.5). `Sync\SyncRosterHandler`
 * reads it; the org-structure import (2.9) is its only writer so far, and only ever adds rows.
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

    /**
     * Every `cav_position_id` that already has a mapping, whatever unit it points at — the import
     * (2.9) skips these so an Admin's hand-corrected mapping is never overwritten.
     *
     * @return Collection<int, int>
     */
    public function mappedPositionIds(): Collection;

    public function create(int $unitId, int $cavPositionId): UnitBilletMapping;
}
