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
}
