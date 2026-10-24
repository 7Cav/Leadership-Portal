<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Add a node to the chain of command (AD-18). Admin-only — enforced by the controller
 * (AD-6) before this ever runs, not here, since the server-side gate is a request-shaped
 * concern and this command has no request to inspect.
 *
 * Guards the one domain rule request validation can't express on its own: echelon ordering.
 * `Rule::enum`/`exists:units,id` in the controller confirm `type` and `parent_id` are each
 * individually well-formed, but neither knows that a root must be a Regiment or that a child
 * must sit exactly one rank below its parent — that needs the parent's own type, which is
 * business logic, not request shape, so it lives here rather than in the controller.
 *
 * The path computation itself stays entirely in {@see UnitRepository::create()}, since Deptrac
 * keeps DB access there — duplicating it here would just be a second place for the two to
 * drift apart. The write itself is staged through `AuditEnvelope` (AD-16) — a reason is never
 * required for org-structure edits, only for personnel actions and report sends.
 */
class CreateUnit
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidUnitPlacementException when the requested placement breaks echelon
     *                                       ordering — caught by the controller and turned
     *                                       into a form validation error.
     */
    public function handle(User $actor, UnitType $type, string $name, ?string $callsign, ?int $parentId): Unit
    {
        if ($parentId === null) {
            if ($type !== UnitType::Regiment) {
                throw InvalidUnitPlacementException::rootMustBeRegiment($type);
            }
        } else {
            // `parent_id` was already confirmed to exist by the controller's `exists:units,id`
            // rule, so a null find() here would mean that guarantee broke, not a user error —
            // let it surface as a TypeError from the null-safe operator below rather than
            // silently accept an inconsistent write.
            $parent = $this->units->find($parentId);

            if (! $type->canSitUnder($parent->type) || ! self::groupParentAllowed($type, $parent)) {
                throw InvalidUnitPlacementException::wrongRankUnderParent($type, $parent->type);
            }
        }

        // A group made by hand is a staff group: ELOA, Reservists and New Recruits are the import's own, found by callsign.
        $kind = $type === UnitType::Group ? UnitKind::Staff : UnitKind::Line;

        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.created',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->units->create($type, $name, $callsign, $parentId, $kind),
        );
    }

    /**
     * A group nests only under a staff group (a department under its portfolio): never under ELOA, Reservists or New
     * Recruits, which hold troopers, not units. Shared with {@see MoveUnit}.
     */
    public static function groupParentAllowed(UnitType $type, Unit $parent): bool
    {
        return $type !== UnitType::Group || $parent->type !== UnitType::Group || $parent->kind === UnitKind::Staff;
    }
}
