<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Set or clear a unit's game or team label (decided by the product owner 2026-10-24): DEVCOM's units play one game each
 * ("WARDOGS"), and nothing in Cav's data says which, so an Admin sets it by hand. Shown next to the unit's name on the
 * org page, the roster and the rollcall pickers; it changes no scope, placement or KPI.
 *
 * Admin-only, enforced by the controller (AD-6), same as {@see CreateUnit}. Audited through the envelope (AD-16) as an
 * org-structure edit, so no reason is required. A blank label clears it; an unchanged one writes nothing and logs
 * nothing.
 */
class SetUnitLabel
{
    public const MAX_LENGTH = 40;

    public function __construct(
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(User $actor, Unit $unit, ?string $label): Unit
    {
        $label = trim((string) $label);
        $label = $label === '' ? null : $label;

        if ($label === $unit->label) {
            return $unit;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.labelled',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->units->setLabel($unit, $label),
        );
    }
}
