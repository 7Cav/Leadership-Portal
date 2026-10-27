<?php

namespace App\Repositories\UnitActingLeadRepository;

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitActingLead;
use App\Models\User;
use App\Repositories\UnitActingLeadRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements UnitActingLeadRepository
{
    public function unitsLedBy(User $user): Collection
    {
        if ($user->cav_id === null) {
            return collect();
        }

        return Unit::query()
            ->select('units.*')
            ->join('unit_acting_leads', 'unit_acting_leads.unit_id', '=', 'units.id')
            ->join('troopers', 'troopers.id', '=', 'unit_acting_leads.trooper_id')
            ->where('troopers.cav_id', $user->cav_id)
            ->whereIn('troopers.status', TrooperStatus::servingValues())
            ->orderBy('units.id')
            ->get()
            ->toBase();
    }

    public function leadsOf(array $unitIds): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return Trooper::query()
            ->select('troopers.*', 'unit_acting_leads.unit_id as acting_unit_id')
            ->join('unit_acting_leads', 'unit_acting_leads.trooper_id', '=', 'troopers.id')
            ->whereIn('unit_acting_leads.unit_id', $unitIds)
            ->whereIn('troopers.status', TrooperStatus::servingValues())
            ->get()
            ->keyBy(fn (Trooper $trooper) => (int) $trooper->getAttribute('acting_unit_id'))
            ->toBase();
    }

    public function forUnit(int $unitId): ?UnitActingLead
    {
        return UnitActingLead::query()->with('trooper')->where('unit_id', $unitId)->first();
    }

    public function unitIds(): Collection
    {
        return UnitActingLead::query()->orderBy('unit_id')->pluck('unit_id')->map(fn ($id) => (int) $id);
    }

    public function assign(int $unitId, int $trooperId, ?int $assignedBy): UnitActingLead
    {
        return UnitActingLead::query()->create(['unit_id' => $unitId, 'trooper_id' => $trooperId, 'assigned_by' => $assignedBy]);
    }

    public function remove(int $unitId): ?UnitActingLead
    {
        return DB::transaction(function () use ($unitId) {
            $row = UnitActingLead::query()->where('unit_id', $unitId)->lockForUpdate()->first();
            $row?->delete();

            return $row;
        });
    }
}
