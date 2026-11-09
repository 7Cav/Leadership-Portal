<?php

namespace App\Repositories;

use App\Models\CommandRole;
use App\Models\Trooper;
use App\Models\UnitType;
use Illuminate\Support\Collection;

/**
 * Who stands in while a unit's lead billet is empty, for display only: the next serving holder of its command team by
 * seniority (a battalion's XO then SGM, a company's XO then 1SG, a platoon's PSG, a section's ASL). It grants nothing
 * and is never the unit's lead anywhere else; an Admin-assigned acting lead, or anyone filling the lead billet, leads
 * instead, so nothing is stored and it ends on its own.
 */
final readonly class LeadStandin
{
    public function __construct(
        public Trooper $trooper,
        public CommandRole $role,
    ) {}

    /**
     * The stand-in from `$team` (the unit's serving command team, {@see UnitCommandMappingRepository::teamsOf()}), or
     * null when nobody below the lead billet serves.
     *
     * @param  Collection<int, Trooper>  $team
     */
    public static function pick(UnitType $type, Collection $team): ?self
    {
        $lead = CommandRole::leadOf($type);
        if ($lead === null) {
            return null;
        }

        return $team
            ->map(fn (Trooper $trooper) => ($role = CommandRole::fromBillet($trooper->billet, $type)) === null ? null : new self($trooper, $role))
            ->filter(fn (?self $standin) => $standin !== null && $lead->isSeniorTo($standin->role))
            ->sortBy(fn (self $standin) => [$standin->role->seniority(), $standin->trooper->id])
            ->first();
    }

    /**
     * The vacant lead's label: "CO vacant" (a battalion's, company's or the regiment's), "PL vacant", "SL vacant" while
     * someone stands in (named only in the details panel's Lead line), or "HQ vacant" when nobody in the team serves. Null for a unit
     * with no lead billet (a group), which keeps "Lead: vacant".
     */
    public static function label(UnitType $type, ?self $standin): ?string
    {
        $lead = CommandRole::leadOf($type);
        if ($lead === null) {
            return null;
        }

        return $standin === null
            ? 'HQ vacant'
            : "{$lead->value} vacant";
    }
}
