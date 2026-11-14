<?php

namespace App\Repositories;

use App\Models\Unit;
use App\Models\UnitBilletMapping;
use Illuminate\Support\Collection;

/**
 * Sole DB access for the curated billet → primary unit mapping (AD-2, 2.5): a section for the line and, since
 * 2026-10-24, the HQ node or group a staff, ELOA, Reservist or New Recruit billet places its holder in. `Sync\SyncRosterHandler`
 * reads it; the org-structure import (2.9) adds rows and repoints only rows it owns (`imported_unit_id` still equal
 * to `unit_id`), and an Admin assigns or moves a billet from Org structure (`AssignBillet`): a correction made by
 * hand is left alone by the import.
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
     * Every mapping as `cav_position_id => unit_id`, whatever unit type it points at: the roster sync's placement
     * lookup (2026-10-24), ahead of the command mappings.
     *
     * @return Collection<int, int>
     */
    public function unitIdsByPositionId(): Collection;

    /**
     * Every mapping as `cav_position_id => [unitId, importedUnitId]`, for the import (2.9): `importedUnitId` is the
     * unit the import last placed the billet in, null when it never wrote or confirmed the row. A row whose two
     * agree is the import's own; one where they differ was corrected by hand.
     *
     * @return Collection<int, array{unitId: int, importedUnitId: ?int}>
     */
    public function forImportByPositionId(): Collection;

    /**
     * Whether any billet maps to `$unit` — `unit_billet_mappings.unit_id` restricts deletes, so
     * `DeleteUnit` checks this first to refuse with a reason instead of a raw constraint error.
     */
    public function hasMappings(Unit $unit): bool;

    /** A new mapping written by the import, owned by it from the start. */
    public function create(int $unitId, int $cavPositionId): UnitBilletMapping;

    /**
     * An Admin's placement of the billet in `$unitId`: a new row for a billet nothing placed, owned by nobody, or the
     * existing row pointed there, keeping the unit the import last put it in. Either way the import reads it as set by
     * hand and keeps it, unless it is where the import would put it anyway. Returns the row with its unit before the
     * change loaded as `previousUnit` (null for a new row), for the audit entry.
     */
    public function assign(int $cavPositionId, int $unitId): UnitBilletMapping;

    /**
     * Undo an Admin's placement of the billet: back to the unit the import last put it in, or, for a billet the import
     * never placed, no placement at all (the row is deleted). Returns the row with `previousUnit` and `unit` loaded for
     * the audit entry (`unit` null once deleted), or null when the billet has no placement.
     */
    public function unassign(int $cavPositionId): ?UnitBilletMapping;

    /**
     * Every placement as `cav_position_id => [unitId, importedUnitId]`, the same as {@see forImportByPositionId()},
     * locked for the caller's transaction.
     *
     * @return Collection<int, array{unitId: int, importedUnitId: ?int}>
     */
    public function lockAll(): Collection;

    /**
     * Point the billet's mapping at `$unitId` and record that the import put it there: a repoint of a row the import
     * owns, or the adoption of an unowned row Cav agrees with (`unit_id` unchanged).
     */
    public function placeFromImport(int $cavPositionId, int $unitId): void;
}
