<?php

namespace App\Integration\DriveDownload;

use RuntimeException;
use Throwable;

/**
 * A Google Drive link the counseling import (#565) could not turn into a PDF. The seam raises this for every failure,
 * so a caller can never mistake an empty answer for a file. `$reason` says which, and the message is plain English the
 * import shows on the row.
 */
class DriveDownloadFailed extends RuntimeException
{
    private function __construct(
        public readonly DriveDownloadFailure $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function notDriveLink(): self
    {
        return new self(DriveDownloadFailure::NotDriveLink, 'not a Google Drive file link');
    }

    public static function folder(): self
    {
        return new self(DriveDownloadFailure::Folder, 'folder links are not supported');
    }

    public static function notPublic(): self
    {
        return new self(DriveDownloadFailure::NotPublic, 'not publicly shared / not a PDF');
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(DriveDownloadFailure::TooLarge, 'larger than '.intdiv($maxBytes, 1024 * 1024).' MB');
    }

    public static function unreachable(?Throwable $previous = null): self
    {
        return new self(DriveDownloadFailure::Unreachable, 'Google Drive could not be reached; try again later', $previous);
    }
}
