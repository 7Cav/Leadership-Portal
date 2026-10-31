<?php

namespace App\Commands\Practices;

use App\Models\Unit;
use InvalidArgumentException;

/** A practice slot change that cannot be made. The message is written for the leader. */
class InvalidPracticeSlotException extends InvalidArgumentException
{
    public static function notASection(Unit $unit): self
    {
        return new self("{$unit->name} is not a section. Practice slots are set per section.");
    }

    public static function outOfRange(): self
    {
        return new self('Pick a weekday and a start time between 0000Z and 2359Z.');
    }

    public static function noSlot(): self
    {
        return new self('This section has no practice slot to move or cancel. Set one first.');
    }

    public static function alreadyCaptured(): self
    {
        return new self("This week's practice was already read from the game server, so its time can no longer change.");
    }

    public static function outsideWeek(): self
    {
        return new self('A practice can only move to another time in the same Cav week, Saturday 0000Z to Friday 2359Z.');
    }
}
