<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Re-parent a unit (AD-18). Admin-only — enforced by the controller (AD-6), same as
 * {@see CreateUnit}. Guards the same echelon-ordering rule `CreateUnit` guards, plus the one
 * placement error only a move can create: a cycle, where the chosen parent is one of the unit's
 * own descendants (or the unit itself). The transactional subtree path recompute stays in
 * {@see UnitRepository::move()}, since Deptrac keeps DB access there. A no-op move (parent
 * unchanged) never reaches `AuditEnvelope` — no state changes, so nothing is logged.
 */
class MoveUnit
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidUnitPlacementException when the requested placement breaks echelon
     *                                       ordering or introduces a cycle — caught by the
     *                                       controller and turned into a form validation error.
     */
    public function handle(User $actor, Unit $unit, ?int $newParentId): Unit
    {
        if ($newParentId === $unit->parent_id) {
            return $unit;
        }

        if ($newParentId === null) {
            if ($unit->type !== UnitType::Regiment) {
                throw InvalidUnitPlacementException::rootMustBeRegiment($unit->type);
            }

            return $unit;
        }

        // `parent_id` was already confirmed to exist by the controller's `exists:units,id`
        // rule, so a null find() here would mean that guarantee broke, not a user error — let
        // it fail loudly below (a null `$newParent` breaks on the first property access)
        // rather than silently accept an inconsistent write.
        $newParent = $this->units->find($newParentId);

        if (str_starts_with($newParent->path, $unit->path)) {
            throw InvalidUnitPlacementException::cyclicParent($unit->name, $newParent->name);
        }

        if ($unit->type->rank() !== $newParent->type->rank() + 1) {
            throw InvalidUnitPlacementException::wrongRankUnderParent($unit->type, $newParent->type);
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.moved',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->units->move($unit, $newParentId),
        );
    }
}
