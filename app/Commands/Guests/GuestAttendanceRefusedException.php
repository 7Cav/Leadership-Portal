<?php

namespace App\Commands\Guests;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A guest entry no leader may add or remove, refused before anything is written. `reason` names the rule in a word the
 * rollcall can act on, the message says it in plain words for a toast:
 *
 * - `not_a_section`, `before_floor`, `confirmed`: the host practice's week cannot change here;
 * - `unknown_trooper`, `not_serving`, `not_placed`, `own_section`: the trooper cannot be a guest;
 * - `already_listed`: they are already recorded at this practice (by the server or a leader);
 * - `not_listed`: there is no hand-added entry for them to remove;
 * - `captured`: the game server saw them, which a leader cannot take back.
 */
class GuestAttendanceRefusedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notASection(int $unitId): self
    {
        return new self("This unit is not a section; guests attend a section's practice.", 'not_a_section');
    }

    public static function weekBeforeFloor(CarbonInterface $weekStart): self
    {
        return new self("The week of {$weekStart->format('dMy')} is too far back to change.", 'before_floor');
    }

    public static function confirmed(): self
    {
        return new self('This practice is confirmed, so its guests can no longer change.', 'confirmed');
    }

    public static function unknownTrooper(): self
    {
        return new self('That trooper was not found. Search again.', 'unknown_trooper');
    }

    public static function notServing(string $name): self
    {
        return new self("{$name} is no longer serving, so they cannot be added as a guest.", 'not_serving');
    }

    public static function notPlaced(string $name): self
    {
        return new self("{$name} is not placed in a section, so there is no section of their own to make up.", 'not_placed');
    }

    public static function ownSection(string $name): self
    {
        return new self("{$name} is in this section. Mark them on the rollcall instead.", 'own_section');
    }

    public static function alreadyListed(string $name): self
    {
        return new self("{$name} is already listed as a guest at this practice.", 'already_listed');
    }

    public static function notListed(): self
    {
        return new self('That trooper is not listed as a guest you added at this practice.', 'not_listed');
    }

    public static function captured(string $name): self
    {
        return new self("{$name} was seen by the game server at this practice, which cannot be removed by hand.", 'captured');
    }
}
