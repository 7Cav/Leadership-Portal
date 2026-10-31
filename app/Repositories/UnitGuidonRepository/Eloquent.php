<?php

namespace App\Repositories\UnitGuidonRepository;

use App\Models\UnitGuidon;
use App\Repositories\UnitGuidonRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements UnitGuidonRepository
{
    public function all(): Collection
    {
        return UnitGuidon::query()->get()->keyBy('unit_id')->toBase();
    }

    public function find(int $unitId): ?UnitGuidon
    {
        return UnitGuidon::query()->where('unit_id', $unitId)->first();
    }

    public function designsFor(iterable $units, bool $inherit = false): array
    {
        $designs = [];
        $all = null;
        foreach ($units as $unit) {
            $all ??= $this->all();
            $designs[$unit->id] = UnitGuidon::designFor($all, $unit, $inherit);
        }

        return $designs;
    }

    public function save(int $unitId, array $design, ?int $updatedBy): UnitGuidon
    {
        // An upsert on the UNIQUE `unit_id`, then a read of the row it landed on, so two first saves of one unit
        // queue on the row rather than racing to insert it (the same shape as the platoon topic's).
        return DB::transaction(function () use ($unitId, $design, $updatedBy) {
            $row = [
                'unit_id' => $unitId,
                'format' => $design['format'],
                'primary_color' => $design['primary'],
                'secondary_color' => $design['secondary'],
                'top_left_text' => $design['topLeft'],
                'bottom_right_text' => $design['bottomRight'],
                'top_align' => $design['topAlign'],
                'bottom_align' => $design['bottomAlign'],
                'large_text' => $design['large'],
                'updated_by' => $updatedBy,
            ];
            UnitGuidon::query()->upsert([$row], ['unit_id'], array_keys(array_diff_key($row, ['unit_id' => true])));

            return UnitGuidon::query()->where('unit_id', $unitId)->lockForUpdate()->sole();
        });
    }
}
