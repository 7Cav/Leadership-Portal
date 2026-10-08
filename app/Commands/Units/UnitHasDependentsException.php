<?php

namespace App\Commands\Units;

use RuntimeException;

/**
 * A unit cannot be deleted while it still has dependents (AD-22 protects portal-owned data from
 * silent orphaning): a child unit, or a Cav billet mapped to the unit (2.9).
 */
class UnitHasDependentsException extends RuntimeException
{
    public static function hasChildren(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\" — it still has child units. Re-parent or remove them first.",
        );
    }

    public static function hasBilletMappings(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\" — Cav billets are still mapped to it, so troopers would lose their section.",
        );
    }
}
