<?php

namespace App\Commands\Games;

use RuntimeException;

/** A game could not be added as asked (#388). The message is shown to the Admin. */
class InvalidGameException extends RuntimeException
{
    public static function nameWithoutLetters(): self
    {
        return new self('The name needs at least one letter or digit.');
    }
}
