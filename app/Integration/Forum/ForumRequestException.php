<?php

namespace App\Integration\Forum;

use RuntimeException;
use Throwable;

/**
 * A forum OAuth/API call failed or returned an unusable payload. Thrown from the
 * Integration layer; CompleteForumLogin (a CommandHandler) catches it and rethrows
 * a CommandHandler-layer `ForumLoginException` the controller can convert into the
 * "back to login, no partial session" response (AC #5) — the controller must not
 * depend on this Integration-layer type directly (Deptrac). No FQCN reference here
 * on purpose: Pint would promote it to a use-import and create Integration → Commands.
 */
class ForumRequestException extends RuntimeException
{
    public static function tokenExchangeFailed(int $status): self
    {
        return new self("Forum token exchange failed (HTTP {$status}).");
    }

    public static function profileFetchFailed(int $status): self
    {
        return new self("Forum profile fetch failed (HTTP {$status}).");
    }

    public static function malformedToken(): self
    {
        return new self('Forum token response did not contain an access token.');
    }

    public static function malformedProfile(): self
    {
        return new self('Forum profile response was missing a user id or username.');
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self('The 7Cav forum could not be reached.', previous: $previous);
    }

    public static function malformedResponse(Throwable $previous): self
    {
        return new self('The 7Cav forum returned a response that could not be parsed.', previous: $previous);
    }
}
