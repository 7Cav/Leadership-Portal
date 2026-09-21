<?php

namespace App\Repositories\TrooperRepository;

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Repositories\TrooperRepository;
use Illuminate\Support\Facades\DB;

class Eloquent implements TrooperRepository
{
    public function sync(array $rows, array $activeCavIds): array
    {
        return DB::transaction(function () use ($rows, $activeCavIds) {
            $upserted = $this->upsertActive($rows);
            $inactivated = $this->markDepartedExcept($activeCavIds);

            return [...$upserted, 'inactivated' => $inactivated];
        });
    }

    public function upsertActive(array $rows): array
    {
        $inserted = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $trooper = Trooper::updateOrCreate(
                ['cav_id' => $row['cav_id']],
                [
                    'rank' => $row['rank'],
                    'mos' => $row['mos'],
                    'tis' => $row['tis'],
                    'tig' => $row['tig'],
                    'status' => $row['status'],
                ],
            );

            $trooper->wasRecentlyCreated ? $inserted++ : $updated++;
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    public function markDepartedExcept(array $activeCavIds): int
    {
        return Trooper::query()
            ->whereNotIn('cav_id', $activeCavIds)
            ->where('status', '!=', TrooperStatus::Inactive->value)
            ->update(['status' => TrooperStatus::Inactive->value]);
    }

    public function updatePrimarySections(array $cavIdToSectionId): int
    {
        $updated = 0;

        foreach ($cavIdToSectionId as $cavId => $sectionId) {
            $updated += Trooper::query()
                ->where('cav_id', $cavId)
                ->update(['primary_section_id' => $sectionId]);
        }

        return $updated;
    }
}
