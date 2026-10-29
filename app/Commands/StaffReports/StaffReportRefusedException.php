<?php

namespace App\Commands\StaffReports;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A staff report that cannot be sent, refused before anything is written. `field` is the form
 * field the message belongs on, `reason` names the rule:
 *
 * - `unknown_trooper`: no such trooper;
 * - `not_owed`: the trooper was not on a platoon or company HQ when the week ended, or no longer serves;
 * - `before_floor`, `future`: the week cannot be reported;
 * - `already_sent`: the report for the week was sent, and a sent report is final;
 * - `no_practice`: "Yes" with no practice picked, or "No" with practices picked;
 * - `not_a_section`: a pick that is not a section.
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
        return new self("{$name} was not on a platoon or company HQ at the end of the week of {$weekStart->format('dMy')}, so there is no staff report to send for it.", 'not_owed', 'report');
    }

    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self("The week of {$weekStart->format('dMy')} is too far back to change.", 'before_floor', 'report');
    }

    public static function future(): self
    {
        return new self('A future week cannot be reported yet.', 'future', 'report');
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
}
