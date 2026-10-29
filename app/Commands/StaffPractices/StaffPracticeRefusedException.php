<?php

namespace App\Commands\StaffPractices;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A staff practice entry that cannot be made or removed, refused before anything is written (#448). `field` is the form
 * field the message belongs on, `reason` names the rule:
 *
 * - `unknown_trooper`, `not_staff`, `not_serving`: the trooper cannot have a staff entry;
 * - `not_a_section`: practices are a section's;
 * - `before_floor`, `future`: the week cannot change;
 * - `locked`: the staff member's HQ sent its report for the week, or they sent their own staff report for it;
 * - `already_recorded`: the trooper is already recorded at that practice, as staff or as a guest of the section (the
 *   message says which, since a guest entry shows on the section's rollcall, not in the staff list);
 * - `not_recorded`: there is no staff entry to remove.
 */
class StaffPracticeRefusedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function unknownTrooper(): self
    {
        return new self('That trooper was not found.', 'unknown_trooper', 'trooper');
    }

    public static function notStaff(string $name): self
    {
        return new self("{$name} is not on a staff, so their practices are on their own section's roll call.", 'not_staff', 'trooper');
    }

    public static function notServing(string $name): self
    {
        return new self("{$name} is no longer serving.", 'not_serving', 'trooper');
    }

    public static function notASection(): self
    {
        return new self('Pick the section whose practice they attended.', 'not_a_section', 'section_id');
    }

    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self("The week of {$weekStart->format('dMy')} is too far back to change.", 'before_floor', 'week');
    }

    public static function outsideWindow(CarbonInterface $weekStart, int $weeks): self
    {
        return new self('The week of '.$weekStart->format('dMy').' is too far back. A staff practice can be entered for this week and the '.($weeks - 1).' before it.', 'outside_window', 'week');
    }

    public static function future(): self
    {
        return new self('A future week has no practice yet.', 'future', 'week');
    }

    public static function locked(CarbonInterface $weekStart): self
    {
        return new self("The report for the week of {$weekStart->format('dMy')} has been sent, so that week can no longer change.", 'locked', 'week');
    }

    /** The staff member sent their own staff report for the week, which froze its entries. */
    public static function staffReportSent(string $name, CarbonInterface $weekStart): self
    {
        return new self("{$name} sent their staff report for the week of {$weekStart->format('dMy')}, so that week can no longer change.", 'locked', 'week');
    }

    public static function alreadyRecorded(string $name): self
    {
        return new self("{$name} is already recorded at that practice.", 'already_recorded', 'section_id');
    }

    /**
     * The trooper is already on that practice as a guest (audit L2): recorded while they were in a section, before they
     * moved to the staff. One row per trooper and practice holds either kind, so the refusal says where it is.
     */
    public static function alreadyAGuest(string $name, bool $captured): self
    {
        $how = $captured ? 'seen by the game server' : "added by the section's leader";

        return new self("{$name} is already listed as a guest at that practice, {$how}. That entry is on the section's roll call, so there is nothing to add here.", 'already_recorded', 'section_id');
    }

    public static function notRecorded(): self
    {
        return new self('There is no staff entry for that practice to remove.', 'not_recorded', 'section_id');
    }
}
