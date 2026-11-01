<?php

namespace App\Commands\Units;

use RuntimeException;

/**
 * A unit cannot be deleted while it still has dependents (AD-22 protects portal-owned data from
 * silent orphaning): a child unit, a Cav billet mapped to the unit (2.9), a staff grant on it
 * (1.12), a recorded practice (3.3), a weekly report (5.6), a company's or a section's template (3.5) or a platoon's
 * training plan (#380). Each is a `restrictOnDelete` foreign key to `units`, so without this the delete would die in
 * the database as a 500. A platoon's training topic cascades in the database instead, but is refused here too: it is
 * part of its week's report record.
 */
class UnitHasDependentsException extends RuntimeException
{
    public const UNDO_WINDOW = 'You can undo this for 60 minutes after adding it, while it is still empty.';

    /** An undo of an add, refused because the unit is no longer as it was added. */
    public static function notUndoable(): self
    {
        return new self(self::UNDO_WINDOW);
    }

    public static function hasChildren(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\": it still has units under it. Move or delete them first.",
        );
    }

    public static function hasBilletMappings(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\": Cav billets are still mapped to it, so troopers would lose their section.",
        );
    }

    public static function hasStaffGrants(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\": staff grants still give users scope over it. Revoke them in Staff grants first.",
        );
    }

    public static function hasPractices(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\": it has recorded attendance, and that history must be kept.",
        );
    }

    public static function hasWeeklyReports(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\": it has weekly reports, and that report history must be kept.",
        );
    }

    public static function hasTemplates(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\": it has template versions, and that template history must be kept.",
        );
    }

    public static function hasTrainingPlans(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\": it has training plans, and that plan history must be kept.",
        );
    }

    public static function hasTrainingTopics(string $unitName): self
    {
        return new self(
            "Cannot delete \"{$unitName}\": it has weekly training topics, and that report history must be kept.",
        );
    }
}
