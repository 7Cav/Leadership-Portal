<?php

namespace App\Repositories\UnitBilletMappingRepository;

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
}
