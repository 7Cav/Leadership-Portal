<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitBilletMappingRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Remove a node from the chain of command (AD-18, AD-22). Admin-only — enforced by the
 * controller (AD-6), same as {@see CreateUnit}. Refuses when anything still hangs off the unit
 * so an Admin gets a clear reason instead of the DB's raw `restrictOnDelete` constraint error,
 * which surfaces as a 500.
 *
 * Every table with a `restrictOnDelete` foreign key to `units` is checked here, up front: child
 * units, billet mappings (2.9), staff grants (1.12), section practices (3.3) and weekly reports
 * (5.6). A new table taking that kind of key to `units` needs its check added below. Troopers
 * (`primary_section_id`) are `nullOnDelete` and command mappings are `cascadeOnDelete`, so
 * neither blocks.
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
        private readonly PortalRoleGrantRepository $grants,
        private readonly SectionPracticeRepository $practices,
        private readonly WeeklyReportRepository $reports,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws UnitHasDependentsException when the unit still has child units, billet mappings,
     *                                    staff grants, recorded practices or weekly reports.
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

        // A grant is the one dependent an Admin can clear from the UI (Staff grants), so the
        // message says where.
        if ($this->grants->hasGrantsOn($unit)) {
            throw UnitHasDependentsException::hasStaffGrants($unit->name);
        }

        // Attendance and reports are history: there is no UI to remove them, by design, so a
        // unit that has any stays in the tree.
        if ($this->practices->hasPracticesFor($unit)) {
            throw UnitHasDependentsException::hasPractices($unit->name);
        }

        if ($this->reports->hasReportsFor($unit)) {
            throw UnitHasDependentsException::hasWeeklyReports($unit->name);
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
