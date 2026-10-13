<?php

namespace App\Integration\BattleMetrics;

use App\Integration\Crcon\Data\CrconPresence;
use RuntimeException;
use Throwable;

/**
 * BattleMetrics could not tell us who was present. The seam raises this instead of returning an
 * empty list so a caller can never mistake "telemetry down" for "nobody attended" — an empty
 * {@see CrconPresence} means BattleMetrics answered and the window
 * was genuinely empty.
 */
class BattleMetricsUnavailable extends RuntimeException
{
    private function __construct(
        public readonly BattleMetricsFailureReason $reason,
        string $message,
        ?Throwable $previous = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function misconfigured(string $detail): self
    {
        return new self(BattleMetricsFailureReason::Misconfigured, "BattleMetrics is not configured: {$detail}.");
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self(BattleMetricsFailureReason::Unreachable, 'BattleMetrics could not be reached.', $previous);
    }

    public static function httpError(int $status): self
    {
        return new self(BattleMetricsFailureReason::HttpError, "BattleMetrics answered with an error (HTTP {$status}).", status: $status);
    }

    public static function malformed(string $detail): self
    {
        return new self(BattleMetricsFailureReason::Malformed, "BattleMetrics response was unusable: {$detail}.");
    }
}
