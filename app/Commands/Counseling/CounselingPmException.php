<?php

namespace App\Commands\Counseling;

use RuntimeException;
use Throwable;

/**
 * A counseling PM could not be sent (#565), so the step that needed it did not happen and the record is as it was.
 * `$needsConsent`: the user has to let the portal send PMs from their forum account first (no grant in the session, it
 * expired, or the forum no longer accepts it). Every message is written here and names no token.
 */
class CounselingPmException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $needsConsent = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function consentNeeded(): self
    {
        return new self('Let the portal send PMs from your forum account first.', needsConsent: true);
    }

    public static function noForumAccount(string $who): self
    {
        return new self("{$who} has no forum account the portal knows, so the PM cannot be sent.");
    }

    public static function refused(?int $status, Throwable $previous): self
    {
        if ($status === 401 || $status === 403) {
            return new self('The forum no longer accepts the portal sending PMs for you. Allow it again.', needsConsent: true, previous: $previous);
        }

        return new self('The forum did not send the PM'.($status === null ? ' (it could not be reached)' : " (HTTP {$status})").'. Nothing was changed; try again.', previous: $previous);
    }
}
