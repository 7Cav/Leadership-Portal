<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\User;
use App\Repositories\UnitBilletMappingRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Remove a node from the chain of command (AD-18, AD-22). Admin-only — enforced by the
 * controller (AD-6), same as {@see CreateUnit}. Refuses when the unit still has children so an
 * Admin gets a clear reason instead of the DB's raw `restrictOnDelete` constraint error.
 *
 * The issue's own acceptance criteria also name dependent troopers as a delete-blocking
 * condition, but the `troopers` table doesn't exist until Epic 2's roster sync — there is
 * nothing to check yet.
 *
 * Returns the deleted `Unit` (rather than void) so `AuditEnvelope` has a target to log — its
 * attributes are still populated in memory after `delete()`, since deleting a row doesn't clear
 * the model that referenced it.
 */
class DeleteUnit
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly UnitBilletMappingRepository $mappings,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws UnitHasDependentsException when the unit still has child units or billet mappings.
     */
    public function handle(User $actor, Unit $unit): Unit
    {
        if ($this->units->hasChildren($unit)) {
            throw UnitHasDependentsException::hasChildren($unit->name);
        }

        // Every section the Cav import (2.9) creates carries billet mappings, and
        // `unit_billet_mappings.unit_id` restricts the delete — refuse with a reason instead.
        if ($this->mappings->hasMappings($unit)) {
            throw UnitHasDependentsException::hasBilletMappings($unit->name);
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.deleted',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($unit) {
                $this->units->delete($unit);

                return $unit;
            },
        );
    }
}
