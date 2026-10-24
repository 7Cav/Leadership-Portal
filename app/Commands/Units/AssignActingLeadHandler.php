<?php

namespace App\Commands\Units;

use App\Models\UnitActingLead;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;

/**
 * Assign an acting lead (decided by the product owner 2026-10-25): a serving trooper who leads a unit the Cav roster
 * names no lead for. The case it exists for is a `DEVCOM Lead`, rostered to DEVCOM staff, who runs one or more DEVCOM
 * starter platoons as their Platoon Leader; the roster does not say which, so an Admin says so here. ScopeResolver
 * then gives them exactly a Platoon Leader's command over the unit, and only while they serve.
 *
 * Admin only, checked against the real flag (AD-6). The unit must be a line unit below the regiment; one acting lead
 * per unit, so a unit that has one is refused until it is removed; a trooper may lead several units. A personnel
 * action, so it is audited with a required reason (AD-16) as `unit.acting_lead_assigned`.
 */
class AssignActingLeadHandler
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly UnitActingLeadRepository $actingLeads,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws ActingLeadForbiddenException when the actor is not an Admin
     * @throws InvalidActingLeadException when the unit or trooper is refused
     * @throws ReasonRequiredException when the reason is blank
     */
    public function handle(User $actor, AssignActingLead $command): UnitActingLead
    {
        if (! $actor->is_admin) {
            throw new ActingLeadForbiddenException;
        }

        $unit = $this->units->find($command->unitId) ?? throw InvalidActingLeadException::noSuchUnit($command->unitId);
        $designation = $unit->callsign ?? $unit->name;

        if (! $unit->isLine() || $unit->type === UnitType::Regiment) {
            throw InvalidActingLeadException::notLeadable($designation);
        }

        $trooper = $this->troopers->find($command->trooperId) ?? throw InvalidActingLeadException::noSuchTrooper($command->trooperId);

        if (! $trooper->status->isServing()) {
            throw InvalidActingLeadException::notServing($trooper->display_name);
        }

        $current = $this->actingLeads->forUnit($unit->id);

        if ($current !== null) {
            throw InvalidActingLeadException::alreadyLed($designation, $current->trooper->display_name);
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.acting_lead_assigned',
            reason: $command->reason,
            reasonRequired: true,
            mutation: fn () => $this->actingLeads->assign($unit->id, $trooper->id, $actor->id),
        );
    }
}
