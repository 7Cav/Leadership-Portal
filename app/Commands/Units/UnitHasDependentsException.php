<?php

namespace App\Commands\Units;

use RuntimeException;

/**
 * A unit cannot be deleted while it still has dependents (AD-22 protects portal-owned data from
 * silent orphaning). For now the only dependent this codebase can see is a child unit — the
 * `troopers` table doesn't exist until Epic 2's roster sync lands, so a trooper-mapped section
 * can't yet be checked here.
 */
class UnitHasDependentsException extends RuntimeException
{
    public static function hasChildren(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\" — it still has child units. Re-parent or remove them first.",
        );
    }
}
