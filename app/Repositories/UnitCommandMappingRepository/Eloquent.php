<?php

namespace App\Repositories\UnitCommandMappingRepository;

use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\User;
use App\Repositories\UnitCommandMappingRepository;
use Illuminate\Support\Collection;

class Eloquent implements UnitCommandMappingRepository
{
    public function unitIdsByPositionId(): Collection
    {
        return UnitCommandMapping::query()->pluck('unit_id', 'cav_position_id');
    }

    public function create(int $unitId, int $cavPositionId): UnitCommandMapping
    {
        return UnitCommandMapping::query()->create(['unit_id' => $unitId, 'cav_position_id' => $cavPositionId]);
    }

    public function commandedUnitFor(User $user): ?Unit
    {
        if ($user->cav_id === null) {
            return null;
        }

        return Unit::query()
            ->select('units.*')
            ->join('unit_command_mappings', 'unit_command_mappings.unit_id', '=', 'units.id')
            ->join('troopers', 'troopers.cav_position_id', '=', 'unit_command_mappings.cav_position_id')
            ->where('troopers.cav_id', $user->cav_id)
            ->where('troopers.status', '!=', TrooperStatus::Inactive->value)
            ->first();
    }
}
