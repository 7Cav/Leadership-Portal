<?php

namespace App\Integration\Steam;

use RuntimeException;

/**
 * Steam could not say whether an account exists: no key configured, Steam unreachable or slow, an error answer or a body
 * that cannot be read. Never carries the transport exception, whose message may hold the request URL and so the key.
 */
class SteamUnavailable extends RuntimeException
{
    public static function misconfigured(string $detail): self
    {
        return new self("Steam is not configured: {$detail}.");
    }

    public static function unreachable(): self
    {
        return new self('Steam could not be reached.');
    }

    public static function httpError(int $status): self
    {
        return new self("Steam answered with an error (HTTP {$status}).");
    }

    public static function malformed(string $detail): self
    {
        return new self("Steam's response was unusable: {$detail}.");
    }
}
