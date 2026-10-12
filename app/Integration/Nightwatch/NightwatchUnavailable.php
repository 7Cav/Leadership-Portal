<?php

namespace App\Integration\Nightwatch;

use App\Integration\Crcon\Data\CrconPresence;
use RuntimeException;
use Throwable;

/**
 * Nightwatch could not tell us who was present. The seam raises this instead of returning an
 * empty list so a caller can never mistake "telemetry down" for "nobody attended" — an empty
 * {@see CrconPresence} means Nightwatch answered and the window was genuinely empty.
 */
class NightwatchUnavailable extends RuntimeException
{
    private function __construct(
        public readonly NightwatchFailureReason $reason,
        string $message,
        ?Throwable $previous = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function misconfigured(string $detail): self
    {
        return new self(NightwatchFailureReason::Misconfigured, "Nightwatch is not configured: {$detail}.");
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self(NightwatchFailureReason::Unreachable, 'Nightwatch could not be reached.', $previous);
    }

    public static function httpError(int $status): self
    {
        return new self(NightwatchFailureReason::HttpError, "Nightwatch answered with an error (HTTP {$status}).", status: $status);
    }

    public static function malformed(string $detail): self
    {
        return new self(NightwatchFailureReason::Malformed, "Nightwatch response was unusable: {$detail}.");
    }

    public static function truncated(): self
    {
        return new self(NightwatchFailureReason::Truncated, 'Nightwatch returned a full page of matches that may not cover the whole window.');
    }
}
