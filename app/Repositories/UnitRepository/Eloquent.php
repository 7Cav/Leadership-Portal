<?php

namespace App\Repositories\UnitRepository;

use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\UnitGame;
use App\Repositories\UnitPath;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as ScalarCollection;
use Illuminate\Support\Facades\DB;

class Eloquent implements UnitRepository
{
    public function __construct(
        private readonly TrooperPlacementRepository $placements,
    ) {}

    public function create(UnitType $type, string $name, ?string $callsign, ?int $parentId, UnitKind $kind = UnitKind::Line): Unit
    {
        return DB::transaction(function () use ($type, $name, $callsign, $parentId, $kind) {
            $parentPath = $parentId !== null ? Unit::whereKey($parentId)->value('path') : '';

            // `forceFill`, not `create`: `parent_id` and `path` are deliberately outside
            // `Unit`'s `#[Fillable]` set (they're computed here, never caller-supplied), so
            // mass assignment would silently drop them.
            $unit = new Unit;
            $unit->forceFill([
                'parent_id' => $parentId,
                'type' => $type,
                'kind' => $kind,
                'name' => $name,
                'callsign' => $callsign,
                'path' => '',
            ])->save();

            $unit->forceFill(['path' => "{$parentPath}{$unit->id}/"])->save();

            return $unit;
        });
    }

    public function setLabel(Unit $unit, ?string $label): Unit
    {
        $unit->update(['label' => $label]);

        return $unit;
    }

    public function setGame(Unit $unit, ?int $gameId): Unit
    {
        $unit->forceFill(['game_id' => $gameId])->save();

        return $unit;
    }

    public function gameOf(int $unitId): ?UnitGame
    {
        // `path` is "1/5/9/": the ids from the root down to this unit, itself last (AD-18).
        $path = Unit::query()->whereKey($unitId)->value('path');
        $chain = array_filter(explode('/', (string) $path), fn (string $id) => $id !== '');

        if ($chain === []) {
            return null;
        }

        return UnitGame::resolve(Unit::query()->whereIn('id', $chain)->get())[$unitId] ?? null;
    }

    public function setLeadPosition(Unit $unit, ?int $cavPositionId): Unit
    {
        $unit->forceFill(['lead_position_id' => $cavPositionId])->save();

        return $unit;
    }

    public function idsOfKind(UnitKind $kind): ScalarCollection
    {
        return Unit::query()->where('kind', $kind->value)->orderBy('id')->pluck('id');
    }

    public function tree(): Collection
    {
        // Sorted in PHP, not by `orderBy('path')`: `path` is text ("1/145/"), so SQL would put
        // "1/145/" before "1/2/" and shuffle siblings once ids pass a digit boundary. Comparing the
        // segments as numbers keeps every unit after its parent and siblings in id order, which is
        // creation order, and the org import creates units in Cav's display order.
        return Unit::query()->get()->sort(fn (Unit $a, Unit $b) => UnitPath::compare($a->path, $b->path))->values();
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

    public function subtree(Unit $unit): Collection
    {
        return Unit::query()->where('path', 'like', "{$unit->path}%")->orderBy('id')->get();
    }

    public function lineSubtree(Unit $unit): Collection
    {
        return Unit::query()->where('path', 'like', "{$unit->path}%")->where('kind', UnitKind::Line->value)->orderBy('id')->get();
    }

    public function owningCompany(Unit $unit): ?Unit
    {
        // The path is read from the stored row, not from `$unit`: a model loaded before the unit was moved still
        // carries its old path and would resolve to the old company. `path` is "1/5/9/": the ids from the root
        // down to this unit, itself last.
        $path = Unit::query()->whereKey($unit->id)->value('path');
        $chain = array_filter(explode('/', (string) $path), fn (string $id) => $id !== '');

        if ($chain === []) {
            return null;
        }

        // At most one company sits on any chain (the echelon rules). `sole()` makes a broken invariant a loud
        // failure instead of an arbitrary pick; none at all is a unit above every company.
        $companies = Unit::query()
            ->whereIn('id', $chain)
            ->where('type', UnitType::Company->value)
            ->get();

        return $companies->isEmpty() ? null : $companies->sole();
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

    public function lockForDelete(Unit $unit): void
    {
        Unit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail(['id']);
    }

    public function hasChildren(Unit $unit): bool
    {
        return $unit->children()->exists();
    }

    public function children(Unit $unit): Collection
    {
        return Unit::query()->where('parent_id', $unit->id)->orderBy('id')->get();
    }

    public function lineChildren(Unit $unit): Collection
    {
        return Unit::query()->where('parent_id', $unit->id)->where('kind', UnitKind::Line->value)->orderBy('id')->get();
    }

    public function hasLineChildren(Unit $unit): bool
    {
        return Unit::query()->where('parent_id', $unit->id)->where('kind', UnitKind::Line->value)->exists();
    }

    public function sectionsAmong(iterable $unitIds): Collection
    {
        $ids = collect($unitIds)->values()->all();

        // Sorted in PHP for the same reason as tree(): a text `orderBy('path')` puts "1/145/"
        // before "1/2/".
        return Unit::query()
            ->with('parent')
            ->where('type', UnitType::Section->value)
            ->whereIn('id', $ids)
            ->get()
            ->sort(fn (Unit $a, Unit $b) => UnitPath::compare($a->path, $b->path))
            ->values();
    }

    public function delete(Unit $unit): void
    {
        DB::transaction(function () use ($unit) {
            // The foreign key nulls `primary_section_id` on its own, but `placement_source` would be
            // left saying who placed a trooper who is now unplaced. Cleared together, first. The ELOA
            // "was" (`last_line_unit_id`) is nulled by its own foreign key.
            $placed = Trooper::query()->where('primary_section_id', $unit->id)->pluck('id');

            Trooper::query()
                ->where('primary_section_id', $unit->id)
                ->update(['primary_section_id' => null, 'placement_source' => null]);

            // They leave the unit now; their placement history keeps the stretch, with no unit once it is gone.
            $this->placements->record(
                $placed->mapWithKeys(fn (int $id) => [$id => [$unit->id, null]])->all(),
                PlacementSource::Manual,
                CarbonImmutable::now(),
            );

            $unit->delete();
        });
    }

    public function withCallsign(string $callsign): Collection
    {
        return Unit::query()->where('callsign', $callsign)->orderBy('id')->get();
    }
}
