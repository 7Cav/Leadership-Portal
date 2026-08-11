<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Rename a unit or change its callsign (AD-18). Admin-only — enforced by the controller (AD-6),
 * same as {@see CreateUnit}. Echelon (`type`) is deliberately not editable here: the issue's own
 * acceptance criteria list name/callsign/leader as editable attributes and treat re-parenting
 * ({@see MoveUnit}) as its own operation, not a rank change. The write itself is staged through
 * `AuditEnvelope` (AD-16), same as `CreateUnit`. A no-op rename (name and callsign both
 * unchanged) never reaches the envelope — the controller's `update()` calls this unconditionally
 * after {@see MoveUnit}, so a pure re-parent must not also log a rename that didn't happen.
 */
class UpdateUnit
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(User $actor, Unit $unit, string $name, ?string $callsign): Unit
    {
        if ($name === $unit->name && $callsign === $unit->callsign) {
            return $unit;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.renamed',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->units->rename($unit, $name, $callsign),
        );
    }
}
