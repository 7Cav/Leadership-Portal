<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Repositories\UnitRepository;

/**
 * Remove a node from the chain of command (AD-18, AD-22). Admin-only — enforced by the
 * controller (AD-6), same as {@see CreateUnit}. Refuses when the unit still has children so an
 * Admin gets a clear reason instead of the DB's raw `restrictOnDelete` constraint error.
 *
 * The issue's own acceptance criteria also name dependent troopers as a delete-blocking
 * condition, but the `troopers` table doesn't exist until Epic 2's roster sync — there is
 * nothing to check yet.
 */
class DeleteUnit
{
    public function __construct(private readonly UnitRepository $units) {}

    /**
     * @throws UnitHasDependentsException when the unit still has child units.
     */
    public function handle(Unit $unit): void
    {
        if ($this->units->hasChildren($unit)) {
            throw UnitHasDependentsException::hasChildren($unit->name);
        }

        $this->units->delete($unit);
    }
}
