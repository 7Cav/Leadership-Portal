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
 * `AuditEnvelope` (AD-16), same as `CreateUnit`.
 */
class UpdateUnit
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(User $actor, Unit $unit, string $name, ?string $callsign): Unit
    {
        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.renamed',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->units->rename($unit, $name, $callsign),
        );
    }
}
