<?php

namespace App\Repositories;

use App\Models\Unit;
use App\Models\UnitType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
     * Every unit in the tree, in depth-first path order so a caller can render it without
     * re-deriving order from `parent_id` walks: each unit follows its parent, and siblings keep id
     * order (creation order, which the org import sets to Cav's display order). Paths are compared
     * segment by segment as numbers ({@see UnitPath::compare()}), so "1/2/" sorts before "1/145/".
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
     * The company that owns `$unit`: the unit itself when it is a company, otherwise its nearest ancestor that is one,
     * or null for a unit above every company (the regiment, a battalion). Found from the materialized path (AD-18)
     * in one query, so it is cheap however deep the unit sits. The path is read from the stored row, so a stale model
     * of a unit that has since moved still resolves to its current company. The echelon rules put at most one company on any
     * unit's chain, so the answer is unique; a chain with two is a broken invariant and fails loudly.
     */
    public function owningCompany(Unit $unit): ?Unit;

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

    /**
     * The subtree rooted at `$unit` as models, itself included, in id order — one `path LIKE`
     * query (AD-18) for a reader that walks the whole chain under a unit at once (5.8's roll-up)
     * and rebuilds the tree from `parent_id` rather than asking {@see children()} once per node.
     *
     * @return Collection<int, Unit>
     */
    public function subtree(Unit $unit): Collection;

    /**
     * Take a row lock on the unit for the rest of the caller's transaction. A writer that inserts a
     * row keyed to the unit (a grant, a practice, a report) needs a shared lock on it for its
     * foreign-key check, so a delete that holds this lock cannot interleave with one: the checks
     * made after it see every dependent committed before it.
     *
     * @throws ModelNotFoundException when the unit is already gone.
     */
    public function lockForDelete(Unit $unit): void;

    public function hasChildren(Unit $unit): bool;

    /**
     * The unit's direct children — not grandchildren — in creation (id) order; sibling `path`s
     * would sort lexicographically ("10/" before "9/"). A non-leaf report aggregates exactly
     * these (AD-13).
     *
     * @return Collection<int, Unit>
     */
    public function children(Unit $unit): Collection;

    /**
     * The sections among `$unitIds` — e.g. a user's resolved scope — each with its `parent`
     * loaded (the platoon a picker groups them under), in the same depth-first path order as
     * {@see tree()}, so siblings sit together and keep id order.
     *
     * @param  iterable<int>  $unitIds
     * @return Collection<int, Unit>
     */
    public function sectionsAmong(iterable $unitIds): Collection;

    /**
     * The FK on `parent_id` (`restrictOnDelete`) would refuse this at the DB level anyway if
     * children exist, but the CommandHandler layer checks {@see hasChildren()} first so an
     * Admin gets a clear reason instead of a raw constraint-violation exception.
     *
     * Troopers placed in the unit are left unplaced: `primary_section_id` and `placement_source`
     * are cleared together, so no trooper keeps a placement source without a section.
     */
    public function delete(Unit $unit): void;

    /**
     * Every unit whose 7Cav designation (`callsign`, e.g. `A/1-7`) is exactly `$callsign`, in id
     * order — a collection, because callsigns are not unique, so a caller resolving one sees an
     * ambiguous match rather than silently picking a node.
     *
     * @return Collection<int, Unit>
     */
    public function withCallsign(string $callsign): Collection;
}
