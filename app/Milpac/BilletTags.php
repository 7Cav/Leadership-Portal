<?php

namespace App\Milpac;

use App\Models\CommandRole;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitLead;
use App\Repositories\UnitLeadRepository;
use Illuminate\Support\Collection;

/**
 * The tag a roster lists a leader under (#443): the short name of the command billet the trooper holds in the unit
 * they are placed in ({@see CommandRole}: CO, XO, 1SG, PL, PSG, SL, ASL, SGM, CSM), or `Acting PL` and the like for a
 * trooper an Admin made a unit's acting lead while it is that unit's lead. Everyone else has no tag. One definition, so
 * the roster, its Sheet and the readiness matrix never disagree. Display only: scope never reads it (AD-8 reads the
 * billet's commanded unit, {@see UnitCommandMappingRepository::commandedUnitFor()}).
 *
 * A billet counts only on the unit it commands, where the sync places its holder: a leader an Admin placed somewhere
 * else holds no command billet of the unit they are listed in. An acting lead's tag wins over their own billet's, since
 * leading the unit is what they are doing there. One that does not lead the unit right now (a billet holder leads it,
 * see {@see UnitLeadRepository}) keeps their own tag.
 */
final class BilletTags
{
    public function __construct(
        private readonly UnitCommandMappingRepository $commands,
        private readonly UnitActingLeadRepository $actingLeads,
        private readonly UnitLeadRepository $leads,
    ) {}

    /**
     * Each tagged trooper's tag, keyed by trooper id. A fixed handful of queries for any number of troopers.
     *
     * @param  Collection<int, Trooper>  $troopers
     * @return Collection<int, string>
     */
    public function of(Collection $troopers): Collection
    {
        $ids = $troopers->pluck('id')->all();
        $commanded = $this->commands->commandedUnitsByTrooperId($ids);
        $acting = $this->actingLeads->unitsLedByTroopers($ids);
        $leads = $this->leads->leadsOf($acting->flatten(1)->pluck('id')->unique()->values()->all());

        return $troopers->mapWithKeys(function (Trooper $trooper) use ($commanded, $acting, $leads) {
            $actingFor = $acting->get($trooper->id, collect())->first(function (Unit $unit) use ($leads, $trooper) {
                $lead = $leads->get($unit->id);

                return $lead?->source === UnitLead::ACTING && $lead->trooper->id === $trooper->id;
            });

            if ($actingFor !== null && ($role = CommandRole::leadOf($actingFor->type)) !== null) {
                return [$trooper->id => "Acting {$role->value}"];
            }

            $unit = $commanded->get($trooper->id);
            $role = $unit !== null && $unit->id === (int) $trooper->primary_section_id ? CommandRole::fromBillet($trooper->billet, $unit->type) : null;

            return $role === null ? [] : [$trooper->id => $role->value];
        });
    }
}
