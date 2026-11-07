<?php

namespace App\Repositories\UnitAccountabilitySettingRepository;

use App\Models\UnitAccountabilitySetting;
use App\Repositories\UnitAccountabilitySettingRepository;

class Eloquent implements UnitAccountabilitySettingRepository
{
    public function all(): array
    {
        return UnitAccountabilitySetting::query()->get(['unit_id', 'options'])
            ->mapWithKeys(fn (UnitAccountabilitySetting $row) => [$row->unit_id => array_values($row->options ?? [])])
            ->all();
    }

    public function set(int $unitId, ?array $options): ?UnitAccountabilitySetting
    {
        if ($options === null) {
            $row = UnitAccountabilitySetting::query()->where('unit_id', $unitId)->first();
            $row?->delete();

            return $row;
        }

        return UnitAccountabilitySetting::query()->updateOrCreate(['unit_id' => $unitId], ['options' => array_values($options)]);
    }
}
