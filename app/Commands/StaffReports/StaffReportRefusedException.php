<?php

namespace App\Commands\StaffReports;

use App\Support\Time\WeekLabel;
use App\Support\Time\WeekOf;
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
 * - `not_in_unit`: a section outside the staff member's own platoon, company or battalion;
 * - `practice_type`: a practice type the HQ does not report (SP or FTX on a platoon or company HQ; SP, operation,
 *   class or event on a battalion HQ), a named type or its fields with "No", or sections with an operation, class or event;
 * - `ftx`: a named type (FTX, operation, class, event) without a name or with a date outside the week, or its fields on an SP;
 * - `presence`: no presence answer, or one other than public server, office hour or none;
 * - `office_hour`: an office hour without its date in the week or its note, or its fields on another answer;
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
        return new self("{$name} was not on a platoon, company or battalion HQ when the ".WeekLabel::phrase($weekStart).' closed, so there is no staff report to send for it.', 'not_owed', 'report');
    }

    /** Sent, and its deadline has passed: it can no longer be edited. */
    public static function locked(CarbonInterface $weekStart): self
    {
        $due = WeekOf::reportDue(WeekOf::start($weekStart));

        return new self('The staff report for the '.WeekLabel::phrase($weekStart).' locked at its deadline, '.WeekLabel::date($due).' '.WeekLabel::zulu($due).', so it can no longer change.', 'locked', 'report');
    }

    /** Editing a sent report is recorded with why. */
    public static function reasonRequired(): self
    {
        return new self('Give a reason for the record before you send the edited report.', 'reason_required', 'reason');
    }

    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self('The '.WeekLabel::phrase($weekStart).' is too far back to change.', 'before_floor', 'report');
    }

    public static function future(): self
    {
        return new self('A future week cannot be reported yet.', 'future', 'report');
    }

    public static function alreadySent(CarbonInterface $weekStart): self
    {
        return new self('The staff report for the '.WeekLabel::phrase($weekStart).' was already sent, so it can no longer change.', 'already_sent', 'report');
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

    /**
     * A type the HQ does not report. `$types` are the ones it does ({@see \App\Commands\Units\AccountabilityRules::staff()}).
     *
     * @param  list<string>  $types
     */
    public static function unknownPracticeType(array $types): self
    {
        $labels = array_map(fn (string $type) => match ($type) {
            'sp' => 'Section practice (SP)',
            'ftx' => 'FTX',
            'operation' => 'Operation',
            'class' => 'Class',
            'event' => 'Other official event',
            default => $type,
        }, $types);
        $last = array_pop($labels);
        $list = $labels === [] ? (string) $last : implode(', ', $labels).' or '.$last;

        return new self($types === [] ? 'Nothing counts for accountability on your HQ. Ask your battalion HQ staff.' : "Pick {$list}.", 'practice_type', 'practice_type');
    }

    public static function ftxWithNo(): self
    {
        return new self('You answered No, so there is nothing to name. Clear it, or answer Yes.', 'practice_type', 'practice_type');
    }

    public static function ftxNameMissing(string $what = 'the FTX'): self
    {
        return new self("Give the name of {$what}.", 'ftx', 'ftx_name');
    }

    public static function ftxDateOutsideWeek(CarbonInterface $weekStart, string $what = 'the FTX'): self
    {
        return new self("Pick the date of {$what}, from ".WeekLabel::date($weekStart).' to '.WeekLabel::date($weekStart->addDays(6)).'.', 'ftx', 'ftx_date');
    }

    public static function ftxFieldsWithSp(): self
    {
        return new self('A section practice takes no name or date. Clear them.', 'ftx', 'ftx_name');
    }

    public static function notInUnit(bool $battalion = false): self
    {
        return new self($battalion
            ? 'Pick only sections in your battalion.'
            : 'Pick only sections in your own platoon or company.', 'not_in_unit', 'section_ids');
    }

    public static function sectionsWithEvent(): self
    {
        return new self('An operation, a class or an event takes no section. Clear the sections, or pick Section practice (SP).', 'practice_type', 'section_ids');
    }

    public static function presenceMissing(): self
    {
        return new self('Answer the presence question.', 'presence', 'presence_type');
    }

    public static function unknownPresence(): self
    {
        return new self('Pick Public server hour, Office hour or No.', 'presence', 'presence_type');
    }

    public static function tooLong(string $field, string $what, int $max): self
    {
        return new self('Keep '.$what." to {$max} characters.", 'too_long', $field);
    }

    public static function officeHourDateOutsideWeek(CarbonInterface $weekStart): self
    {
        return new self('Pick the date of the office hour, from '.WeekLabel::date($weekStart).' to '.WeekLabel::date($weekStart->addDays(6)).'.', 'office_hour', 'office_hour_date');
    }

    public static function officeHourNoteMissing(): self
    {
        return new self('Say in a few words what the office hour was.', 'office_hour', 'office_hour_note');
    }

    public static function officeHourFieldsElsewhere(): self
    {
        return new self('An office hour date and note go with an office hour only. Clear them, or pick Office hour.', 'office_hour', 'office_hour_note');
    }
}
