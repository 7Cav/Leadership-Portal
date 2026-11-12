<?php

namespace App\Repositories\UnitBilletMappingRepository;

use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitType;
use App\Repositories\UnitBilletMappingRepository;
use Illuminate\Support\Collection;

class Eloquent implements UnitBilletMappingRepository
{
    public function sectionIdsByPositionId(): Collection
    {
        return UnitBilletMapping::query()
            ->whereHas('unit', fn ($query) => $query->where('type', UnitType::Section->value))
            ->pluck('unit_id', 'cav_position_id');
    }

    public function unitIdsByPositionId(): Collection
    {
        return UnitBilletMapping::query()->pluck('unit_id', 'cav_position_id');
    }

    public function forImportByPositionId(): Collection
    {
        return UnitBilletMapping::query()
            ->get(['cav_position_id', 'unit_id', 'imported_unit_id'])
            ->mapWithKeys(fn (UnitBilletMapping $row) => [(int) $row->cav_position_id => [
                'unitId' => (int) $row->unit_id,
                'importedUnitId' => $row->imported_unit_id === null ? null : (int) $row->imported_unit_id,
            ]]);
    }

    public function hasMappings(Unit $unit): bool
    {
        return UnitBilletMapping::query()->where('unit_id', $unit->id)->exists();
    }

    public function create(int $unitId, int $cavPositionId): UnitBilletMapping
    {
        return UnitBilletMapping::query()->create(['unit_id' => $unitId, 'cav_position_id' => $cavPositionId, 'imported_unit_id' => $unitId]);
    }

    public function assign(int $cavPositionId, int $unitId): UnitBilletMapping
    {
        $row = UnitBilletMapping::query()->with('unit')->where('cav_position_id', $cavPositionId)->lockForUpdate()->first();

        if ($row === null) {
            $row = UnitBilletMapping::query()->create(['unit_id' => $unitId, 'cav_position_id' => $cavPositionId, 'imported_unit_id' => null]);

            return $row->setRelation('previousUnit', null)->load('unit');
        }

        $previous = $row->unit;
        $row->forceFill(['unit_id' => $unitId])->save();

        return $row->setRelation('previousUnit', $previous)->load('unit');
    }

    public function unassign(int $cavPositionId): ?UnitBilletMapping
    {
        $row = UnitBilletMapping::query()->with('unit')->where('cav_position_id', $cavPositionId)->lockForUpdate()->first();

        if ($row === null) {
            return null;
        }

        $previous = $row->unit;

        if ($row->imported_unit_id === null) {
            $row->delete();

            return $row->setRelation('previousUnit', $previous)->setRelation('unit', null);
        }

        $row->forceFill(['unit_id' => $row->imported_unit_id])->save();

        return $row->setRelation('previousUnit', $previous)->load('unit');
    }

    public function lockAll(): Collection
    {
        return UnitBilletMapping::query()->lockForUpdate()
            ->get(['cav_position_id', 'unit_id', 'imported_unit_id'])
            ->mapWithKeys(fn (UnitBilletMapping $row) => [(int) $row->cav_position_id => [
                'unitId' => (int) $row->unit_id,
                'importedUnitId' => $row->imported_unit_id === null ? null : (int) $row->imported_unit_id,
            ]]);
    }

    public function placeFromImport(int $cavPositionId, int $unitId): void
    {
        UnitBilletMapping::query()->where('cav_position_id', $cavPositionId)->update(['unit_id' => $unitId, 'imported_unit_id' => $unitId, 'updated_at' => now()]);
    }
}
