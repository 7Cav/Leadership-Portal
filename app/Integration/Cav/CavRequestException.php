<?php

namespace App\Integration\Cav;

use RuntimeException;
use Throwable;

/**
 * A Cav roster API call failed or returned an unusable payload. Thrown from the
 * Integration layer; EnrichCavProfile (a CommandHandler) catches it and rethrows a
 * CommandHandler-layer `CavEnrichmentException` the controller can act on — the
 * controller must not depend on this Integration-layer type directly (Deptrac).
 * No FQCN reference here on purpose: Pint would promote it to a use-import and
 * create Integration → Commands.
 */
class CavRequestException extends RuntimeException
{
    public static function profileFetchFailed(int $cavId, int $status): self
    {
        return new self("Cav milpac profile fetch failed for cav id {$cavId} (HTTP {$status}).");
    }

    public static function rosterFetchFailed(string $roster, int $status): self
    {
        return new self("Cav roster fetch failed for {$roster} (HTTP {$status}).");
    }

    public static function notOnRoster(int $forumUserId): self
    {
        return new self("No Cav roster record carries forum user id {$forumUserId}.");
    }

    public static function malformedProfile(): self
    {
        return new self('Cav milpac profile response was missing a usable user envelope.');
    }

    public static function malformedRoster(): self
    {
        return new self('Cav roster response did not contain a profiles map.');
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self('The Cav roster API could not be reached.', previous: $previous);
    }

    public static function malformedResponse(Throwable $previous): self
    {
        return new self('The Cav roster API returned a response that could not be parsed.', previous: $previous);
    }

    public static function missingCredential(): self
    {
        return new self('No Cav roster API key is configured (CAV_API_KEY).');
    }
}
