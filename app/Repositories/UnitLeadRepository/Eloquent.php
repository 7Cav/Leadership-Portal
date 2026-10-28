<?php

namespace App\Repositories\UnitLeadRepository;

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitLead;
use App\Repositories\UnitLeadRepository;
use Illuminate\Support\Collection;

class Eloquent implements UnitLeadRepository
{
    public function __construct(
        private readonly UnitCommandMappingRepository $commands,
        private readonly UnitActingLeadRepository $acting,
    ) {}

    public function leadsOf(array $unitIds): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        $leads = collect();

        foreach ($this->acting->leadsOf($unitIds) as $unitId => $trooper) {
            $leads->put($unitId, new UnitLead($trooper, UnitLead::ACTING));
        }

        foreach ($this->portfolioHolders($unitIds) as $unitId => $trooper) {
            $leads->put($unitId, new UnitLead($trooper, UnitLead::PORTFOLIO));
        }

        // A unit whose lead billet is named (a portfolio, DEVCOM's 1IC, #377) is led by that billet's holder alone: when
        // it is vacant the unit shows vacant, not whichever other billet commands it (DEVCOM's 2IC).
        $named = Unit::query()->whereIn('id', $unitIds)->whereNotNull('lead_position_id')->pluck('id')->map(fn ($id) => (int) $id)->flip();

        foreach ($this->commands->commandersOf($unitIds) as $unitId => $trooper) {
            if (! $named->has($unitId)) {
                $leads->put($unitId, new UnitLead($trooper, UnitLead::BILLET));
            }
        }

        return $leads;
    }

    public function leadableUnitIds(): Collection
    {
        $platoonsAndPortfolios = Unit::query()
            ->where(fn ($query) => $query
                ->where(fn ($platoon) => $platoon->where('type', UnitType::Platoon->value)->where('kind', UnitKind::Line->value))
                ->orWhereNotNull('lead_position_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        return $this->commands->commandedUnitIds()
            ->merge($this->acting->unitIds())
            ->merge($platoonsAndPortfolios)
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * The serving holder of each portfolio's named billet, keyed by unit id.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, Trooper>
     */
    private function portfolioHolders(array $unitIds): Collection
    {
        return Trooper::query()
            ->select('troopers.*', 'units.id as portfolio_unit_id')
            ->join('units', 'units.lead_position_id', '=', 'troopers.cav_position_id')
            ->whereIn('units.id', $unitIds)
            ->whereIn('troopers.status', TrooperStatus::servingValues())
            ->orderBy('troopers.id')
            ->get()
            ->keyBy(fn (Trooper $trooper) => (int) $trooper->getAttribute('portfolio_unit_id'))
            ->toBase();
    }
}
