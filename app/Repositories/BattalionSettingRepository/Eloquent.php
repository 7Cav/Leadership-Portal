<?php

namespace App\Repositories\BattalionSettingRepository;

use App\Models\BattalionSetting;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\BattalionSettingRepository;

class Eloquent implements BattalionSettingRepository
{
    public function battalionOf(int $unitId): ?Unit
    {
        // `path` is "1/5/9/": the ids from the root down to this unit, itself last (AD-18).
        $path = Unit::query()->whereKey($unitId)->value('path');
        $chain = array_filter(explode('/', (string) $path), fn (string $id) => $id !== '');

        if ($chain === []) {
            return null;
        }

        return Unit::query()->whereIn('id', $chain)->where('type', UnitType::Battalion->value)->first();
    }

    public function presenceMinimum(int $battalionId): ?int
    {
        $minutes = BattalionSetting::query()->where('unit_id', $battalionId)->value('presence_minimum_minutes');

        return $minutes === null ? null : (int) $minutes;
    }

    public function setPresenceMinimum(int $battalionId, ?int $minutes): BattalionSetting
    {
        return BattalionSetting::query()->updateOrCreate(['unit_id' => $battalionId], ['presence_minimum_minutes' => $minutes]);
    }
}
