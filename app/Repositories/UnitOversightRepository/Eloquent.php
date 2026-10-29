<?php

namespace App\Repositories\UnitOversightRepository;

use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitOversight;
use App\Models\User;
use App\Repositories\UnitOversightRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements UnitOversightRepository
{
    public function unitsOverseenBy(User $user): Collection
    {
        if ($user->cav_id === null) {
            return collect();
        }

        return Unit::query()
            ->select('units.*')
            ->join('unit_oversights', 'unit_oversights.unit_id', '=', 'units.id')
            ->join('troopers', 'troopers.id', '=', 'unit_oversights.trooper_id')
            ->where('troopers.cav_id', $user->cav_id)
            ->whereIn('troopers.status', TrooperStatus::servingValues())
            ->orderBy('units.id')
            ->get()
            ->toBase();
    }

    public function unitsOverseenByTroopers(array $trooperIds): Collection
    {
        if ($trooperIds === []) {
            return collect();
        }

        return Unit::query()
            ->select('units.*', 'unit_oversights.trooper_id as overseer_trooper_id')
            ->join('unit_oversights', 'unit_oversights.unit_id', '=', 'units.id')
            ->join('troopers', 'troopers.id', '=', 'unit_oversights.trooper_id')
            ->whereIn('troopers.id', $trooperIds)
            ->whereIn('troopers.status', TrooperStatus::servingValues())
            ->orderBy('units.id')
            ->get()
            ->groupBy(fn (Unit $unit) => (int) $unit->getAttribute('overseer_trooper_id'))
            ->map(fn ($units) => $units->toBase())
            ->toBase();
    }

    public function forUnits(array $unitIds): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return UnitOversight::query()
            ->with('trooper')
            ->whereIn('unit_id', $unitIds)
            ->get()
            ->keyBy('unit_id')
            ->toBase();
    }

    public function forUnit(int $unitId): ?UnitOversight
    {
        return UnitOversight::query()->with('trooper')->where('unit_id', $unitId)->first();
    }

    public function set(int $unitId, int $trooperId, ?int $setBy, ?int $setByAdmin, ?int $setAsUnit): UnitOversight
    {
        return DB::transaction(function () use ($unitId, $trooperId, $setBy, $setByAdmin, $setAsUnit) {
            $row = UnitOversight::query()->where('unit_id', $unitId)->lockForUpdate()->first() ?? new UnitOversight(['unit_id' => $unitId]);
            $row->fill([
                'trooper_id' => $trooperId,
                'set_by' => $setBy,
                'set_by_admin' => $setByAdmin,
                'set_as_unit' => $setAsUnit,
            ])->save();

            return $row->load('trooper');
        });
    }

    public function clear(int $unitId): ?UnitOversight
    {
        return DB::transaction(function () use ($unitId) {
            $row = UnitOversight::query()->with('trooper')->where('unit_id', $unitId)->lockForUpdate()->first();
            $row?->delete();

            return $row;
        });
    }
}
