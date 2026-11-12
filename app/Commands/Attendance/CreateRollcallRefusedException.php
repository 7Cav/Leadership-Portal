<?php

namespace App\Commands\Attendance;

use RuntimeException;

/**
 * A roll call that cannot be created (#703): its week follows the three-step flow, which has no create step. Thrown
 * before anything is written.
 */
class CreateRollcallRefusedException extends RuntimeException
{
    public static function notOnTheNewFlow(): self
    {
        return new self('This week follows the three-step roll call, which is not created first.');
    }
}
