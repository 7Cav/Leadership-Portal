<?php

namespace App\Repositories;

use App\Models\Unit;
use App\Models\UnitBilletMapping;
use Illuminate\Support\Collection;

/**
 * Sole DB access for the curated billet → section mapping (AD-2, 2.5). `Sync\SyncRosterHandler`
 * reads it; the org-structure import (2.9) is its only application writer so far, and only ever
 * adds rows (a correction today is a direct DB edit, which the import then leaves alone).
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
     * Every mapping as `cav_position_id => unit_id`, whatever unit type it points at. The import
     * (2.9) never repoints an existing row — not even one it wrote itself that Cav has since moved
     * — so it uses this to skip mapped billets and to report the ones that now disagree with Cav.
     *
     * @return Collection<int, int>
     */
    public function unitIdsByPositionId(): Collection;

    /**
     * Whether any billet maps to `$unit` — `unit_billet_mappings.unit_id` restricts deletes, so
     * `DeleteUnit` checks this first to refuse with a reason instead of a raw constraint error.
     */
    public function hasMappings(Unit $unit): bool;

    public function create(int $unitId, int $cavPositionId): UnitBilletMapping;
}
