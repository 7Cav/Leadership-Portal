<?php

namespace App\Integration\GoogleSheets;

use RuntimeException;
use Throwable;

/**
 * The reports Google Sheet could not take a row: not configured, unreachable, an HTTP error, or refused before sending.
 * Every message is written here and names no credential, token or row content, because the queued push logs it.
 */
class GoogleSheetsUnavailable extends RuntimeException
{
    public static function misconfigured(string $why): self
    {
        return new self("The reports Google Sheet is not configured: {$why}.");
    }

    public static function unreachable(Throwable $previous): self
    {
        // Not the previous message: a transport or token error can quote the request it failed on.
        return new self('The reports Google Sheet could not be reached ('.class_basename($previous).').', 0, $previous);
    }

    public static function httpError(int $status, string $step = 'row append'): self
    {
        return new self("Google Sheets answered HTTP {$status} to the {$step}.");
    }

    public static function malformed(string $why, ?Throwable $previous = null): self
    {
        return new self("Google answered in a shape the portal cannot read: {$why}.", 0, $previous);
    }

    public static function refused(string $why): self
    {
        return new self("A Google Sheets request was refused before sending: {$why}.");
    }
}
