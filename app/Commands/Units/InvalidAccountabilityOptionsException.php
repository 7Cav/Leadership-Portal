<?php

namespace App\Commands\Units;

use InvalidArgumentException;

/** Accountability options that are unknown, empty, or wider than the battalion allows. Written for the staff member. */
class InvalidAccountabilityOptionsException extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('Tick at least one option, or use the inherited ones.');
    }

    public static function unknown(): self
    {
        return new self('Pick from the options listed.');
    }

    public static function wider(): self
    {
        return new self('Tick only options the battalion allows.');
    }
}
