<?php

namespace App\Commands\Attendance;

use App\Support\Time\WeekLabel;
use Carbon\CarbonInterface;
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
        return new self('Each trooper needs a practice mark or a roll call signature. Reload the page and try again.');
    }

    public static function loaIsDerived(int $trooperId): self
    {
        return new self('A trooper cannot be marked LOA by hand: LOA comes from LOA records.');
    }

    /**
     * The troopers are named in `$trooperIds`, not in the message, which stays in plain words for a toast or a
     * log: the caller (the rollcall screen's autosave) drops just these and keeps saving the rest.
     *
     * @param  list<int>  $trooperIds
     */
    public static function loaCovered(array $trooperIds): self
    {
        $exception = new self('These troopers are on LOA this week, so their mark comes from their LOA and cannot be set by hand.');
        $exception->trooperIds = $trooperIds;
        $exception->reason = 'loa_covered';

        return $exception;
    }

    public static function notASection(int $unitId): self
    {
        return new self('This unit is not a section; attendance is marked at a section practice.');
    }

    public static function duplicateTrooper(int $trooperId): self
    {
        return new self('A trooper appears more than once in this change. Reload the page and try again.');
    }

    /** The week is older than `WeekOf::floor()`, too far back to write. */
    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self('The '.WeekLabel::phrase($weekStart).' is too far back to change.');
    }

    /**
     * @param  list<int>  $trooperIds
     */
    public static function notPlacedInSection(array $trooperIds, int $sectionId): self
    {
        $exception = new self(sprintf(
            'Some troopers are not placed in this section, and a trooper is marked only at their own section practice. Reload the page to see the section as it is now.',
            implode(', ', $trooperIds),
            $sectionId,
        ));
        $exception->trooperIds = $trooperIds;
        $exception->reason = 'not_placed';

        return $exception;
    }
}
