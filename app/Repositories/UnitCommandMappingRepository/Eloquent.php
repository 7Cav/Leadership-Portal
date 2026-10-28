<?php

namespace App\Repositories\UnitCommandMappingRepository;

use App\Models\CommandRole;
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

    public function commandedUnitIds(): Collection
    {
        return UnitCommandMapping::query()->distinct()->orderBy('unit_id')->pluck('unit_id')->map(fn ($id) => (int) $id);
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

    public function commandedUnitsByTrooperId(array $trooperIds): Collection
    {
        if ($trooperIds === []) {
            return collect();
        }

        return $this->commandedUnits()
            ->addSelect('troopers.id as commander_trooper_id')
            ->whereIn('troopers.id', $trooperIds)
            ->get()
            ->keyBy(fn (Unit $unit) => (int) $unit->getAttribute('commander_trooper_id'))
            ->toBase();
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

        $types = Unit::query()->whereIn('id', $unitIds)->pluck('type', 'id');

        return $this->teamsOf($unitIds)
            ->map(function (Collection $holders, int $unitId) use ($types) {
                $type = $types->get($unitId);
                $lead = $type === null ? null : CommandRole::leadOf($type);
                $roleOf = fn (Trooper $trooper) => $type === null ? null : CommandRole::fromBillet($trooper->billet, $type);

                // The rest of the command team (XO, 1SG, PSG, ASL, SGM, CSM) never leads the unit, even with the
                // commanding billet vacant (#444). A title naming no command billet at all still can: a staff
                // portfolio's officer, the one billet mapped to their group.
                return $holders->first(fn (Trooper $t) => $lead !== null && $roleOf($t) === $lead)
                    ?? $holders->first(fn (Trooper $t) => $roleOf($t) === null);
            })
            ->filter();
    }

    public function teamsOf(array $unitIds): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return Trooper::query()
            ->select('troopers.*', 'unit_command_mappings.unit_id as commands_unit_id')
            ->join('unit_command_mappings', 'unit_command_mappings.cav_position_id', '=', 'troopers.cav_position_id')
            ->whereIn('unit_command_mappings.unit_id', $unitIds)
            ->whereIn('troopers.status', TrooperStatus::servingValues())
            ->orderBy('troopers.id')
            ->get()
            ->groupBy(fn (Trooper $trooper) => (int) $trooper->getAttribute('commands_unit_id'))
            ->map(fn ($holders) => $holders->values()->toBase())
            ->toBase();
    }
}
