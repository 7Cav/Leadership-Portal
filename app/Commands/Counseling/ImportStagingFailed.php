<?php

namespace App\Commands\Counseling;

use RuntimeException;
use Throwable;

/**
 * The counseling import's staging area (#565, {@see ImportStaging}) could not be written or read: the disk is full or
 * not writable, or a file the batch needs is gone or unreadable. Raised instead of carrying on with a half-written
 * batch, so a failed write shows as an error ("could not be kept on the server") rather than a blank result or a
 * misleading "X was not uploaded". The message is plain English for the Admin; the path stays in the log.
 */
class ImportStagingFailed extends RuntimeException
{
    public function __construct(public readonly string $path, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function write(string $path, ?Throwable $previous = null): self
    {
        return new self($path, 'The import\'s files could not be kept on the server. Try again; if it keeps failing, the server\'s storage needs checking.', $previous);
    }

    public static function read(string $path, ?Throwable $previous = null): self
    {
        return new self($path, 'The import\'s files on the server could not be read. Check the files again.', $previous);
    }
}
