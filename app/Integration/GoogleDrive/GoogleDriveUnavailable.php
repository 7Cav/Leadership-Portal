<?php

namespace App\Integration\GoogleDrive;

use RuntimeException;
use Throwable;

/**
 * Google Drive could not take the document (#381): not configured, unreachable, an HTTP error, or an answer the seam
 * could not read. Every message is written here and names no credential, token or file content, because the queued
 * upload logs it when it gives up.
 */
class GoogleDriveUnavailable extends RuntimeException
{
    public static function misconfigured(string $why): self
    {
        return new self("Google Drive is not configured: {$why}.");
    }

    public static function unreachable(Throwable $previous): self
    {
        // Not the previous message: a transport error can quote the request it failed on.
        return new self('Google Drive could not be reached ('.class_basename($previous).').', 0, $previous);
    }

    public static function httpError(string $step, int $status): self
    {
        return new self("Google Drive answered HTTP {$status} to the {$step}.");
    }

    public static function malformed(string $why): self
    {
        return new self("Google Drive answered in a shape the portal cannot read: {$why}.");
    }

    public static function refused(string $why): self
    {
        return new self("A Google Drive request was refused before sending: {$why}.");
    }
}
