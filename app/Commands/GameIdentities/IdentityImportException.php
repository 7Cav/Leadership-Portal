<?php

namespace App\Commands\GameIdentities;

use RuntimeException;

/** A game identity import that cannot be read or saved as a whole. The message is shown to the leader. */
class IdentityImportException extends RuntimeException
{
    public static function tooLarge(): self
    {
        return new self('The file is larger than 256 KB. Split it and import each part.');
    }

    public static function tooManyLines(): self
    {
        return new self('The file has more than '.IdentityImportParser::MAX_LINES.' identities. Split it and import each part.');
    }

    public static function notText(): self
    {
        return new self('This is not a text file. Save it as .txt or .csv and try again.');
    }

    public static function empty(): self
    {
        return new self('There are no identities to import. Add one line per identity.');
    }

    public static function nothingToImport(): self
    {
        return new self('There is nothing to import: every line is unchanged, skipped or refused.');
    }

    /** The lines or the identities changed between the preview and the import. */
    public static function outdated(): self
    {
        return new self('The identities changed since the preview. Preview the file again.');
    }
}
