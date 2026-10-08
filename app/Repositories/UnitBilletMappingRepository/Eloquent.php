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

    public function hasMappings(Unit $unit): bool
    {
        return UnitBilletMapping::query()->where('unit_id', $unit->id)->exists();
    }

    public function create(int $unitId, int $cavPositionId): UnitBilletMapping
    {
        return UnitBilletMapping::query()->create(['unit_id' => $unitId, 'cav_position_id' => $cavPositionId]);
    }
}
