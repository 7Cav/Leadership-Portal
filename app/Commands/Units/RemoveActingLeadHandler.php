<?php

namespace App\Commands\Units;

use App\Models\UnitActingLead;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;

/**
 * Remove a unit's acting lead (decided 2026-10-25): Admin only (AD-6), audited with a required reason (AD-16) as
 * `unit.acting_lead_removed`. Removing the row ends the command it gave at once. A lead whose trooper stopped serving
 * already confers nothing; removing it clears the record.
 */
class RemoveActingLeadHandler
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly UnitActingLeadRepository $actingLeads,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws ActingLeadForbiddenException when the actor is not an Admin
     * @throws InvalidActingLeadException when the unit does not exist or has no acting lead
     * @throws ReasonRequiredException when the reason is blank
     */
    public function handle(User $actor, RemoveActingLead $command): UnitActingLead
    {
        if (! $actor->is_admin) {
            throw new ActingLeadForbiddenException;
        }

        $unit = $this->units->find($command->unitId) ?? throw InvalidActingLeadException::noSuchUnit($command->unitId);
        $designation = $unit->callsign ?? $unit->name;

        if ($this->actingLeads->forUnit($unit->id) === null) {
            throw InvalidActingLeadException::noneToRemove($designation);
        }

        // Targets the unit, not the removed row, so the entry still matches the audit log's unit filter once the row is
        // gone. The digest reads rows as stored, so it takes the trooper who led it rather than the deleted row.
        $lead = null;
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'unit.acting_lead_removed',
            reason: $command->reason,
            reasonRequired: true,
            mutation: function () use ($unit, $designation, &$lead) {
                $lead = $this->actingLeads->remove($unit->id) ?? throw InvalidActingLeadException::noneToRemove($designation);

                return new AuditedBatch($unit, $lead->trooper);
            },
        );

        return $lead;
    }
}
