<?php

namespace App\Authorization;

use App\Models\CounselingType;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitCommandMappingRepository;
use Illuminate\Support\Collection;

/**
 * Who may file, see, correct and delete a trooper's counseling records (#381, AD-8), decided once per viewer and
 * trooper on top of the {@see ScopeResolver}, the way the regulations and the product owner (2026-10-28) put it:
 *
 *  - **File a DCS**: any leader whose command (billet, acting lead, a held billet, never a staff grant) reaches the
 *    trooper's unit: Section Leader and up, portfolio officers over their departments included. **File an LOR**: only
 *    command from platoon level up (Platoon Leader and Sergeant, company, battalion and regiment command, an acting
 *    lead of a platoon or above). An Admin may file either.
 *  - A command the trooper shares does not count: a leader never files on, or sees the DCSs of, someone who holds
 *    the same unit (an ASL and their Section Leader, a Platoon Sergeant and their Platoon Leader), and nobody files on
 *    themselves. The next echelon up does.
 *  - **See a DCS**: Admins, and every leader who could file one on the trooper, which is the chain from Platoon
 *    Leader up plus the trooper's own Section Leader and ASL. A staff grant does not. **See an LOR**: everyone who
 *    can see the trooper ({@see ScopeResolver::canSee()}). Nothing is seen of a trooper the viewer cannot see.
 *  - **Correct or delete**: Admins, and the commanders of the unit the record was filed through (the issuer and the
 *    chain above them), as long as they still see the record. A record with no issuing unit (filed by an Admin, or its
 *    unit deleted) is the Admins' alone.
 *
 * Writes are judged on real authority narrowed by an active view-as preview ({@see ScopeResolver::writeScope()}); the
 * `ReadOnlyWhileViewingAs` middleware refuses them during a preview anyway. Reads follow the preview through `canSee`.
 */
class CounselingAccess
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitCommandMappingRepository $commands,
        private readonly UnitActingLeadRepository $actingLeads,
    ) {}

    public function for(User $user, Trooper $trooper): CounselingPermissions
    {
        $section = $trooper->primary_section_id;
        $self = $user->cav_id !== null && (int) $user->cav_id === (int) $trooper->cav_id;
        $canSee = $this->scope->canSee($user, $trooper);
        $writeScope = $section === null && ! $user->is_admin ? collect() : $this->scope->writeScope($user);

        $issuing = [CounselingType::Dcs->value => null, CounselingType::Lor->value => null];
        if ($section !== null && ! $self && $writeScope->contains($section)) {
            $reaching = $this->reachingUnits($user, $trooper, $section);
            $issuing[CounselingType::Dcs->value] = $reaching->first();
            $issuing[CounselingType::Lor->value] = $reaching->first(fn (Unit $unit) => self::platoonOrAbove($unit));
        }

        $admin = (bool) $user->is_admin;

        return new CounselingPermissions(
            seesDcs: $canSee && ($admin || $issuing[CounselingType::Dcs->value] !== null),
            seesLor: $canSee,
            filesDcs: ! $self && ($admin || $issuing[CounselingType::Dcs->value] !== null),
            filesLor: ! $self && ($admin || $issuing[CounselingType::Lor->value] !== null),
            admin: $admin,
            self: $self,
            issuingUnits: array_map(fn (?Unit $unit) => $unit?->id, $issuing),
            writeScope: $writeScope->map(fn ($id) => (int) $id)->values()->all(),
        );
    }

    /**
     * The units through which the user's command reaches the trooper's unit, lowest first, without the ones the
     * trooper commands themselves (a peer's shared billet is not a chain of command over them).
     *
     * @return Collection<int, Unit>
     */
    private function reachingUnits(User $user, Trooper $trooper, int $section): Collection
    {
        $units = $this->scope->commandingUnitsOver($user, $section);
        if ($units->isEmpty()) {
            return $units;
        }

        $held = collect();
        if ($trooper->cav_position_id !== null) {
            $held->push($this->commands->unitIdsByPositionId()->get($trooper->cav_position_id));
        }
        $this->actingLeads->leadsOf($units->pluck('id')->all())
            ->filter(fn (Trooper $lead) => $lead->id === $trooper->id)
            ->each(fn (Trooper $lead, int $unitId) => $held->push($unitId));
        $held = $held->filter()->map(fn ($id) => (int) $id);

        return $units->reject(fn (Unit $unit) => $held->contains($unit->id))->values();
    }

    /** A line unit at platoon level or above: what an LOR's issuer must command (a staff group does not count). */
    private static function platoonOrAbove(Unit $unit): bool
    {
        return $unit->type->isLine() && $unit->type->rank() <= UnitType::Platoon->rank();
    }
}
