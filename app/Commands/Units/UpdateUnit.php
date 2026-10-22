<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Edit a unit (AD-18): rename it or change its callsign, and re-parent it through {@see MoveUnit}.
 * Admin-only — enforced by the controller (AD-6), same as {@see CreateUnit}. Echelon (`type`) is
 * deliberately not editable here: the issue's own acceptance criteria list name/callsign/leader as
 * editable attributes and treat re-parenting as its own operation, not a rank change. The write
 * itself is staged through `AuditEnvelope` (AD-16), same as `CreateUnit`. A no-op rename (name and
 * callsign both unchanged) never reaches the envelope, so a pure re-parent logs only `unit.moved`.
 *
 * A move and a rename together are one write: the move runs inside the rename's audited
 * transaction, so a rename that fails also rolls back the move and its `unit.moved` row. The move
 * runs first because it validates before it writes, so a refused re-parent leaves nothing behind.
 */
class UpdateUnit
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
        private readonly MoveUnit $move,
    ) {}

    /**
     * @param  int|null  $parentId  the parent the unit should have; its current one leaves it in place
     *
     * @throws InvalidUnitPlacementException when the re-parent breaks echelon ordering or
     *                                       introduces a cycle; nothing is written.
     */
    public function handle(User $actor, Unit $unit, string $name, ?string $callsign, ?int $parentId): Unit
    {
        if ($name === $unit->name && $callsign === $unit->callsign) {
            return $this->move->handle($actor, $unit, $parentId);
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.renamed',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->units->rename($this->move->handle($actor, $unit, $parentId), $name, $callsign),
        );
    }
}
