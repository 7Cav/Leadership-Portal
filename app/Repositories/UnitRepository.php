<?php

namespace App\Repositories;

use App\Models\Unit;
use App\Models\UnitType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sole DB access for the chain of command (AD-2, AD-18).
 */
class UnitRepository
{
    /**
     * Create a unit under an optional parent, computing its materialized `path` in the same
     * transaction as the insert (AD-18) — a crash between the two would otherwise leave a row
     * with no usable path, silently dropping it from every subtree query. The path can only be
     * known once the id exists, so the row is inserted first (with a placeholder) and then
     * updated with its final path before the transaction commits — no half-written row is ever
     * visible outside it.
     */
    public function create(UnitType $type, string $name, ?string $callsign, ?int $parentId): Unit
    {
        return DB::transaction(function () use ($type, $name, $callsign, $parentId) {
            $parentPath = $parentId !== null ? Unit::whereKey($parentId)->value('path') : '';

            // `forceFill`, not `create`: `parent_id` and `path` are deliberately outside
            // `Unit`'s `#[Fillable]` set (they're computed here, never caller-supplied), so
            // mass assignment would silently drop them.
            $unit = new Unit;
            $unit->forceFill([
                'parent_id' => $parentId,
                'type' => $type,
                'name' => $name,
                'callsign' => $callsign,
                'path' => '',
            ])->save();

            $unit->forceFill(['path' => "{$parentPath}{$unit->id}/"])->save();

            return $unit;
        });
    }

    /**
     * Every unit in the tree, ordered by `path` so a caller can render it depth-first without
     * re-deriving order from `parent_id` walks.
     *
     * @return Collection<int, Unit>
     */
    public function tree(): Collection
    {
        return Unit::query()->orderBy('path')->get();
    }

    public function find(int $id): ?Unit
    {
        return Unit::find($id);
    }

    /**
     * Update a unit's own attributes. `name`/`callsign` are mass-assignable (unlike
     * `parent_id`/`path`), so this never needs `forceFill`.
     */
    public function rename(Unit $unit, string $name, ?string $callsign): Unit
    {
        $unit->update(['name' => $name, 'callsign' => $callsign]);

        return $unit;
    }

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
    public function move(Unit $unit, int $newParentId): Unit
    {
        return DB::transaction(function () use ($unit, $newParentId) {
            $newParent = Unit::whereKey($newParentId)->lockForUpdate()->firstOrFail();
            $oldPath = $unit->path;
            $newOwnPath = "{$newParent->path}{$unit->id}/";

            $descendants = Unit::query()
                ->where('path', 'like', "{$oldPath}%")
                ->where('id', '!=', $unit->id)
                ->lockForUpdate()
                ->get();

            foreach ($descendants as $descendant) {
                $descendant->forceFill([
                    'path' => $newOwnPath.substr($descendant->path, strlen($oldPath)),
                ])->save();
            }

            $unit->forceFill([
                'parent_id' => $newParentId,
                'path' => $newOwnPath,
            ])->save();

            return $unit;
        });
    }

    public function hasChildren(Unit $unit): bool
    {
        return $unit->children()->exists();
    }

    /**
     * The FK on `parent_id` (`restrictOnDelete`) would refuse this at the DB level anyway if
     * children exist, but the CommandHandler layer checks {@see hasChildren()} first so an
     * Admin gets a clear reason instead of a raw constraint-violation exception.
     */
    public function delete(Unit $unit): void
    {
        $unit->delete();
    }
}
