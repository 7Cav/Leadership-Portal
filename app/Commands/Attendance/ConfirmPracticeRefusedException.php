<?php

namespace App\Commands\Attendance;

use App\Support\Time\WeekLabel;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A practice confirmation the practice does not allow yet (#634): its hour has not ended, it is confirmed as held while
 * a trooper still has no practice mark, or as not held while a trooper is marked as attended. Thrown before anything is
 * written, so the rollcall controller answers it as a 422 with the reason.
 */
class ConfirmPracticeRefusedException extends RuntimeException
{
    public static function notOver(CarbonImmutable $end): self
    {
        return new self('The practice is not over yet. Confirm it after it ends, '.$end->format('D').' '.WeekLabel::date($end).' '.$end->format('Hi').'Z.');
    }

    public static function unmarked(int $count): self
    {
        return new self($count === 1
            ? 'One trooper has no practice mark yet. Mark everyone before you confirm the practice as held.'
            : "{$count} troopers have no practice mark yet. Mark everyone before you confirm the practice as held.");
    }

    public static function attended(): self
    {
        return new self('A trooper is marked as attended, so the practice was held. Change that mark first, or confirm it as held.');
    }
}
