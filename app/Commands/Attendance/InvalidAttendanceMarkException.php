<?php

namespace App\Commands\Attendance;

use InvalidArgumentException;

/**
 * A submitted attendance batch asks for something no leader may record. Thrown before anything is
 * written — the batch is all-or-nothing — and lives in the CommandHandler layer so the rollcall
 * controller (3.8) can turn it into a form error (Controller → CommandHandler is allowed).
 */
class InvalidAttendanceMarkException extends InvalidArgumentException
{
    public static function nothingToRecord(int $trooperId): self
    {
        return new self("Trooper {$trooperId}'s mark carries neither a practice status nor a rollcall signature.");
    }

    public static function loaIsDerived(int $trooperId): self
    {
        return new self("Trooper {$trooperId} cannot be marked loa by hand — LOA status is derived from LOA data, never entered.");
    }

    public static function duplicateTrooper(int $trooperId): self
    {
        return new self("Trooper {$trooperId} appears more than once in one attendance batch.");
    }

    /**
     * @param  list<int>  $trooperIds
     */
    public static function notPlacedInSection(array $trooperIds, int $sectionId): self
    {
        return new self(sprintf(
            'Troopers %s are not placed in section %d; a trooper is marked only against their own section\'s practice.',
            implode(', ', $trooperIds),
            $sectionId,
        ));
    }
}
