<?php

namespace App\Repositories\UnitRepository;

use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\UnitRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as ScalarCollection;
use Illuminate\Support\Facades\DB;

class Eloquent implements UnitRepository
{
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

    public function tree(): Collection
    {
        return Unit::query()->orderBy('path')->get();
    }

    public function find(int $id): ?Unit
    {
        return Unit::find($id);
    }

    public function allIds(): ScalarCollection
    {
        return Unit::query()->pluck('id');
    }

    public function subtreeIds(Unit $unit): ScalarCollection
    {
        return Unit::query()->where('path', 'like', "{$unit->path}%")->pluck('id');
    }

    public function rename(Unit $unit, string $name, ?string $callsign): Unit
    {
        $unit->update(['name' => $name, 'callsign' => $callsign]);

        return $unit;
    }

    public function move(Unit $unit, int $newParentId): Unit
    {
        return DB::transaction(function () use ($unit, $newParentId) {
            // Re-fetch under lock before reading `path`: the caller's `$unit` was hydrated
            // before this transaction opened, so trusting its in-memory `path` would let two
            // concurrent moves of the same unit both compute a new path from the same stale
            // ancestor chain — the exact interleaving a transaction is supposed to prevent.
            $unit = Unit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
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

    public function delete(Unit $unit): void
    {
        $unit->delete();
    }
}
