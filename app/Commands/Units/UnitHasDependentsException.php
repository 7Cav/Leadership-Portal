<?php

namespace App\Commands\Units;

use RuntimeException;

/**
 * A unit cannot be deleted while it still has dependents (AD-22 protects portal-owned data from
 * silent orphaning): a child unit, a Cav billet mapped to the unit (2.9), a staff grant on it
 * (1.12), a recorded practice (3.3), a weekly report (5.6) or a company's template version (3.5). Each is a `restrictOnDelete` foreign
 * key to `units`, so without this the delete would die in the database as a 500.
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

    public static function hasStaffGrants(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\" — staff grants still give users scope over it. Revoke them in Staff grants first.",
        );
    }

    public static function hasPractices(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\" — attendance has been recorded for it, and that history must not be orphaned.",
        );
    }

    public static function hasWeeklyReports(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\" — it has weekly reports, and that report history must not be orphaned.",
        );
    }

    public static function hasTemplates(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\" — it has template versions, and that template history must not be orphaned.",
        );
    }
}
