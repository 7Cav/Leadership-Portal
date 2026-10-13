<?php

namespace App\Commands\Attendance;

use RuntimeException;

/**
 * A submitted attendance batch asks for something no leader may record. Thrown before anything is
 * written — the batch is all-or-nothing — and lives in the CommandHandler layer so the rollcall
 * controller (3.8) can turn it into a form error (Controller → CommandHandler is allowed).
 */
class InvalidAttendanceMarkException extends RuntimeException
{
    /**
     * The troopers the batch was refused for, when the refusal is about specific troopers
     * (`notPlacedInSection`, `loaCovered`) — so a caller can say which marks cannot be saved. Empty otherwise.
     *
     * @var list<int>
     */
    public array $trooperIds = [];

    /**
     * Why those troopers were refused, when `$trooperIds` is set: `not_placed` (moved out of the section) or
     * `loa_covered` (their leave covers the week). A caller drops a moved trooper for good, but keeps a covered
     * trooper's signature, which is a separate fact the handler accepts. Null otherwise.
     */
    public ?string $reason = null;

    public static function nothingToRecord(int $trooperId): self
    {
        return new self("Trooper {$trooperId}'s mark carries neither a practice status nor a rollcall signature.");
    }

    public static function loaIsDerived(int $trooperId): self
    {
        return new self("Trooper {$trooperId} cannot be marked loa by hand — LOA status is derived from LOA data, never entered.");
    }

    /**
     * The troopers are named in `$trooperIds`, not in the message, which stays in plain words for a toast or a
     * log: the caller (the rollcall screen's autosave) drops just these and keeps saving the rest.
     *
     * @param  list<int>  $trooperIds
     */
    public static function loaCovered(array $trooperIds): self
    {
        $exception = new self('These troopers are on leave this week, so their status comes from their leave and cannot be set by hand.');
        $exception->trooperIds = $trooperIds;
        $exception->reason = 'loa_covered';

        return $exception;
    }

    public static function notASection(int $unitId): self
    {
        return new self("Unit {$unitId} is not a section; attendance is marked against a section's practice.");
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
        $exception = new self(sprintf(
            'Troopers %s are not placed in section %d; a trooper is marked only against their own section\'s practice.',
            implode(', ', $trooperIds),
            $sectionId,
        ));
        $exception->trooperIds = $trooperIds;
        $exception->reason = 'not_placed';

        return $exception;
    }
}
