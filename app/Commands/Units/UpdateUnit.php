<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Repositories\UnitRepository;

/**
 * Rename a unit or change its callsign (AD-18). Admin-only — enforced by the controller (AD-6),
 * same as {@see CreateUnit}. Echelon (`type`) is deliberately not editable here: the issue's own
 * acceptance criteria list name/callsign/leader as editable attributes and treat re-parenting
 * ({@see MoveUnit}) as its own operation, not a rank change.
 */
class UpdateUnit
{
    public function __construct(private readonly UnitRepository $units) {}

    public function handle(Unit $unit, string $name, ?string $callsign): Unit
    {
        return $this->units->rename($unit, $name, $callsign);
    }
}
