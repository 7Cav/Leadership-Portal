<?php

namespace App\Repositories\UnitCommandMappingRepository;

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\User;
use App\Repositories\UnitCommandMappingRepository;
use Illuminate\Database\Eloquent\Builder;
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

    public function repoint(int $cavPositionId, int $unitId): void
    {
        UnitCommandMapping::query()->where('cav_position_id', $cavPositionId)->update(['unit_id' => $unitId]);
    }

    public function deleteExcept(array $cavPositionIds): int
    {
        return UnitCommandMapping::query()->whereNotIn('cav_position_id', $cavPositionIds)->delete();
    }

    public function commandedUnitFor(User $user): ?Unit
    {
        if ($user->cav_id === null) {
            return null;
        }

        return $this->commandedUnits()->where('troopers.cav_id', $user->cav_id)->first();
    }

    public function commandedUnitsByCavId(array $cavIds): Collection
    {
        return $this->commandedUnits()
            ->addSelect('troopers.cav_id as commander_cav_id')
            ->whereIn('troopers.cav_id', $cavIds)
            ->get()
            ->keyBy('commander_cav_id');
    }

    /**
     * Units joined to the serving trooper whose primary billet commands them — an allow-list of
     * standings ({@see TrooperStatus::serving()}, shared with the grant and sign-in checks), so a
     * departed (`inactive`) trooper's last billet, or any standing added later, confers nothing.
     *
     * @return Builder<Unit>
     */
    private function commandedUnits(): Builder
    {
        return Unit::query()
            ->select('units.*')
            ->join('unit_command_mappings', 'unit_command_mappings.unit_id', '=', 'units.id')
            ->join('troopers', 'troopers.cav_position_id', '=', 'unit_command_mappings.cav_position_id')
            ->whereIn('troopers.status', TrooperStatus::servingValues());
    }

    public function commandersOf(array $unitIds): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return Trooper::query()
            ->select('troopers.*', 'unit_command_mappings.unit_id as commands_unit_id')
            ->join('unit_command_mappings', 'unit_command_mappings.cav_position_id', '=', 'troopers.cav_position_id')
            ->whereIn('unit_command_mappings.unit_id', $unitIds)
            ->whereIn('troopers.status', TrooperStatus::servingValues())
            // A section's Section Leader and Assistant Section Leader billets both map to it, and the last row per unit
            // wins below: the assistant is read first, so the Section Leader is named whenever the billet is held.
            ->orderByRaw("CASE WHEN LOWER(troopers.billet) LIKE '%assistant section leader%' THEN 0 ELSE 1 END")
            ->orderBy('troopers.id')
            ->get()
            ->keyBy(fn (Trooper $trooper) => (int) $trooper->getAttribute('commands_unit_id'));
    }
}
