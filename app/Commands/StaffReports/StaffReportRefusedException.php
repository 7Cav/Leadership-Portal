<?php

namespace App\Commands\StaffReports;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A staff report that cannot be sent, refused before anything is written. `field` is the form
 * field the message belongs on, `reason` names the rule:
 *
 * - `unknown_trooper`: no such trooper;
 * - `not_owed`: the trooper was not on a platoon, company or battalion HQ when the week ended, or no longer serves;
 * - `before_floor`, `future`: the week cannot be reported;
 * - `already_sent`: the report for the week was sent, and a sent report is final;
 * - `no_practice`: "Yes" with no practice picked, or "No" with practices picked;
 * - `not_a_section`: a pick that is not a section;
 * - `practice_type`: a practice type other than SP or FTX, or an FTX or its fields with "No";
 * - `ftx`: an FTX without a name or with a date outside the week, or FTX fields on an SP;
 * - `office_hour`: an office hour from anyone but battalion HQ staff, without its date in the week or its note, with
 *   sections picked, or its fields on another type;
 * - `too_long`: free text over its limit.
 */
class StaffReportRefusedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function unknownTrooper(): self
    {
        return new self('That trooper was not found.', 'unknown_trooper', 'report');
    }

    public static function notOwed(string $name, CarbonInterface $weekStart): self
    {
        return new self("{$name} was not on a platoon, company or battalion HQ at the end of the week of {$weekStart->format('dMy')}, so there is no staff report to send for it.", 'not_owed', 'report');
    }

    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self("The week of {$weekStart->format('dMy')} is too far back to change.", 'before_floor', 'report');
    }

    public static function future(): self
    {
        return new self('A future week cannot be reported yet.', 'future', 'report');
    }

    public static function weekNotEnded(CarbonInterface $weekStart): self
    {
        return new self("The week of {$weekStart->format('dMy')} has not ended yet. You can send its staff report from {$weekStart->addWeek()->format('dMy')}.", 'not_ended', 'report');
    }

    public static function alreadySent(CarbonInterface $weekStart): self
    {
        return new self("The staff report for the week of {$weekStart->format('dMy')} was already sent, so it can no longer change.", 'already_sent', 'report');
    }

    public static function noPractice(): self
    {
        return new self('Pick at least one section practice you attended, or answer No.', 'no_practice', 'section_ids');
    }

    public static function practicesWithNo(): self
    {
        return new self('You answered No, so no practice can be picked. Clear the picks, or answer Yes.', 'no_practice', 'section_ids');
    }

    public static function notASection(): self
    {
        return new self('Pick only sections whose practice you attended.', 'not_a_section', 'section_ids');
    }

    public static function unknownPracticeType(): self
    {
        return new self('Pick Section practice (SP), FTX or, on a battalion HQ, Office hour.', 'practice_type', 'practice_type');
    }

    public static function ftxWithNo(): self
    {
        return new self('You answered No, so there is no FTX to name. Clear it, or answer Yes.', 'practice_type', 'practice_type');
    }

    public static function ftxNameMissing(): self
    {
        return new self('Give the name of the FTX.', 'ftx', 'ftx_name');
    }

    public static function ftxDateOutsideWeek(CarbonInterface $weekStart): self
    {
        return new self("Pick the date of the FTX, from {$weekStart->format('dMy')} to {$weekStart->addDays(6)->format('dMy')}.", 'ftx', 'ftx_date');
    }

    public static function ftxFieldsWithSp(): self
    {
        return new self('An FTX name and date go with an FTX only. Clear them, or pick FTX.', 'ftx', 'ftx_name');
    }

    public static function tooLong(string $field, string $what, int $max): self
    {
        return new self('Keep '.$what." to {$max} characters.", 'too_long', $field);
    }

    public static function officeHourNotBattalion(): self
    {
        return new self('An office hour counts for battalion HQ staff only. Pick Section practice (SP) or FTX.', 'office_hour', 'practice_type');
    }

    public static function officeHourDateOutsideWeek(CarbonInterface $weekStart): self
    {
        return new self("Pick the date of the office hour, from {$weekStart->format('dMy')} to {$weekStart->addDays(6)->format('dMy')}.", 'office_hour', 'office_hour_date');
    }

    public static function officeHourNoteMissing(): self
    {
        return new self('Say in a few words what the office hour was.', 'office_hour', 'office_hour_note');
    }

    public static function officeHourWithSections(): self
    {
        return new self('An office hour takes no section practice. Clear the sections, or pick Section practice (SP).', 'office_hour', 'section_ids');
    }

    public static function officeHourFieldsElsewhere(): self
    {
        return new self('An office hour date and note go with an office hour only. Clear them, or pick Office hour.', 'office_hour', 'office_hour_note');
    }
}
