<?php

namespace App\Commands\Practices;

use InvalidArgumentException;

/** A presence minimum outside one to sixty minutes. The message is written for the staff member. */
class InvalidPresenceMinimumException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Pick a minimum between '.PresenceMinimum::LOWEST.' and '.PresenceMinimum::HIGHEST.' minutes. A section practice is one hour.');
    }
}
