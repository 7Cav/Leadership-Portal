<?php

namespace App\Repositories;

use App\Models\Unit;
use App\Models\UnitType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as ScalarCollection;

/**
 * Sole DB access for the chain of command (AD-2, AD-18).
 */
interface UnitRepository
{
    /**
     * Create a unit under an optional parent, computing its materialized `path` in the same
     * transaction as the insert (AD-18) — a crash between the two would otherwise leave a row
     * with no usable path, silently dropping it from every subtree query. The path can only be
     * known once the id exists, so the row is inserted first (with a placeholder) and then
     * updated with its final path before the transaction commits — no half-written row is ever
     * visible outside it.
     */
    public function create(UnitType $type, string $name, ?string $callsign, ?int $parentId): Unit;

    /**
     * Every unit in the tree, ordered by `path` so a caller can render it depth-first without
     * re-deriving order from `parent_id` walks.
     *
     * @return Collection<int, Unit>
     */
    public function tree(): Collection;

    public function find(int $id): ?Unit;

    /**
     * Every unit id in the tree — the Admin tier's full scope (AD-6, AD-8).
     *
     * @return ScalarCollection<int, int>
     */
    public function allIds(): ScalarCollection;

    /**
     * The subtree rooted at `$unit`, including itself — a cheap `path LIKE 'ancestorpath%'`
     * query (AD-18), the same idiom {@see move()} uses to find every descendant to re-path.
     *
     * @return ScalarCollection<int, int>
     */
    public function subtreeIds(Unit $unit): ScalarCollection;

    /**
     * Update a unit's own attributes. `name`/`callsign` are mass-assignable (unlike
     * `parent_id`/`path`), so this never needs `forceFill`.
     */
    public function rename(Unit $unit, string $name, ?string $callsign): Unit;

    /**
     * Re-parent a unit, recomputing the materialized `path` of the unit and every descendant in
     * one transaction (AD-18) — a partial recompute would leave some descendants scoped under
     * the old ancestor chain and others under the new one. Every touched row is locked for the
     * duration of the transaction: this is the first place a unit's own row is written a second
     * time after creation, so a concurrent move of an overlapping subtree must not interleave.
     *
     * Placement rules (cycle, echelon rank) are the caller's job (the CommandHandler layer) —
     * this method trusts `$newParentId` is already a valid destination.
     */
    public function move(Unit $unit, int $newParentId): Unit;

    public function hasChildren(Unit $unit): bool;

    /**
     * The FK on `parent_id` (`restrictOnDelete`) would refuse this at the DB level anyway if
     * children exist, but the CommandHandler layer checks {@see hasChildren()} first so an
     * Admin gets a clear reason instead of a raw constraint-violation exception.
     */
    public function delete(Unit $unit): void;
}
