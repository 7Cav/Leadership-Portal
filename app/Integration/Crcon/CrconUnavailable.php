<?php

namespace App\Integration\Crcon;

use RuntimeException;
use Throwable;

/**
 * CRCON could not tell us who was present. The seam raises this instead of returning an empty
 * list so a caller can never mistake "telemetry down" for "nobody attended" — an empty
 * {@see Data\CrconPresence} means CRCON answered and the window was genuinely empty.
 */
class CrconUnavailable extends RuntimeException
{
    private function __construct(
        public readonly CrconFailureReason $reason,
        string $message,
        ?Throwable $previous = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function misconfigured(string $detail): self
    {
        return new self(CrconFailureReason::Misconfigured, "CRCON is not configured: {$detail}.");
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self(CrconFailureReason::Unreachable, 'The CRCON server could not be reached.', $previous);
    }

    public static function httpError(int $status): self
    {
        return new self(CrconFailureReason::HttpError, "CRCON answered with an error (HTTP {$status}).", status: $status);
    }

    public static function malformed(string $detail): self
    {
        return new self(CrconFailureReason::Malformed, "CRCON response was unusable: {$detail}.");
    }
}
