<?php

namespace App\Commands\WeeklyReports;

use RuntimeException;

/**
 * A report draft or send carries content no leader may record: a reason for a trooper who is not
 * in the section, an unknown reason, or unaccounted troopers on a roll-up. Thrown before anything
 * is written, so the report controller can turn it into a form error.
 */
class InvalidReportContentException extends RuntimeException
{
    public static function notASection(int $unitId): self
    {
        return new self("Unit {$unitId} is not a section; only a section accounts for its own troopers.");
    }

    /**
     * @param  list<int>  $trooperIds
     */
    public static function notInSection(array $trooperIds, int $sectionId): self
    {
        return new self(sprintf('Troopers %s are not placed in section %d.', implode(', ', $trooperIds), $sectionId));
    }

    public static function unknownReason(string $reason): self
    {
        return new self("\"{$reason}\" is not a reason a leader can pick for an unaccounted trooper.");
    }

    public static function duplicateTrooper(int $trooperId): self
    {
        return new self("Trooper {$trooperId} is accounted for more than once.");
    }
}
