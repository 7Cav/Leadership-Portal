<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\UnitRepository;

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
 * drift apart. Kept as its own Command, not folded into the controller, because AD-15 routes
 * every write through one: this is the audited-write seam Story 1.10 attaches to.
 */
class CreateUnit
{
    public function __construct(private readonly UnitRepository $units) {}

    /**
     * @throws InvalidUnitPlacementException when the requested placement breaks echelon
     *                                       ordering — caught by the controller and turned
     *                                       into a form validation error.
     */
    public function handle(UnitType $type, string $name, ?string $callsign, ?int $parentId): Unit
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

            if ($type->rank() !== $parent->type->rank() + 1) {
                throw InvalidUnitPlacementException::wrongRankUnderParent($type, $parent->type);
            }
        }

        return $this->units->create($type, $name, $callsign, $parentId);
    }
}
