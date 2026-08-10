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
 * Thin on purpose: the one invariant that matters (path computed atomically with the
 * insert) already lives in {@see UnitRepository::create()} because Deptrac keeps DB access
 * there — duplicating it here would just be a second place for the two to drift apart.
 * Kept as its own Command, not folded into the controller, because AD-15 routes every
 * write through one: this is the audited-write seam Story 1.10 attaches to.
 */
class CreateUnit
{
    public function __construct(private readonly UnitRepository $units) {}

    public function handle(UnitType $type, string $name, ?string $callsign, ?int $parentId): Unit
    {
        return $this->units->create($type, $name, $callsign, $parentId);
    }
}
