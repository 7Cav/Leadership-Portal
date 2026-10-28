<?php

namespace App\Commands\Games;

use RuntimeException;

/** A game could not be added or changed as asked (#388). The message is shown to the Admin. */
class InvalidGameException extends RuntimeException
{
    public static function nameWithoutLetters(): self
    {
        return new self('The name needs at least one letter or digit.');
    }

    /**
     * @param  list<string>  $kinds  the kinds an identity of the game still uses
     */
    public static function idKindInUse(array $kinds): self
    {
        return new self('Cannot remove '.implode(', ', $kinds).': a linked identity uses '.(count($kinds) === 1 ? 'it' : 'them').'. Unlink those identities first.');
    }
}
