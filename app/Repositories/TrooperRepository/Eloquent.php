<?php

namespace App\Repositories\TrooperRepository;

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Repositories\TrooperRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements TrooperRepository
{
    public function sync(array $rows, array $activeCavIds, array $unreadableCavIds = []): array
    {
        return DB::transaction(function () use ($rows, $activeCavIds, $unreadableCavIds) {
            $upserted = $this->upsertActive($rows);
            $inactivated = $this->markDepartedExcept($activeCavIds);

            if ($unreadableCavIds !== []) {
                Trooper::query()->whereIn('cav_id', $unreadableCavIds)->update(['cav_position_id' => null, 'cav_billet' => null]);
            }

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
                    'username' => $row['username'] ?? null,
                    'rank' => $row['rank'],
                    'mos' => $row['mos'],
                    'tis' => $row['tis'],
                    'tig' => $row['tig'],
                    'status' => $row['status'],
                    'cav_position_id' => $row['cav_position_id'] ?? null,
                    'cav_billet' => $row['cav_billet'] ?? null,
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

        // Grouped by target section rather than one UPDATE per trooper: a full-roster sync can
        // resolve several hundred troopers into a handful of sections, and this turns that into
        // one query per section instead of one per trooper.
        foreach (collect($cavIdToSectionId)->groupBy(fn ($sectionId) => $sectionId, preserveKeys: true) as $sectionId => $cavIds) {
            $updated += Trooper::query()
                ->whereIn('cav_id', $cavIds->keys())
                ->update(['primary_section_id' => $sectionId]);
        }

        return $updated;
    }

    public function idsPlacedIn(int $sectionId, array $trooperIds): Collection
    {
        return Trooper::query()
            ->where('primary_section_id', $sectionId)
            ->whereIn('id', $trooperIds)
            ->orderBy('id')
            ->pluck('id');
    }

    public function assignedWithin(Unit $unit): Collection
    {
        return Trooper::query()->assignedWithin($unit)->orderBy('id')->pluck('id');
    }

    public function unplaced(): EloquentCollection
    {
        return Trooper::query()
            ->whereNull('primary_section_id')
            ->where('status', '!=', TrooperStatus::Inactive->value)
            ->orderByRaw('username is null')
            ->orderBy('username')
            ->orderBy('cav_id')
            ->get();
    }

    public function ranksByCavId(array $cavIds): Collection
    {
        return Trooper::query()->whereIn('cav_id', $cavIds)->whereNotNull('rank')->pluck('rank', 'cav_id');
    }
}
