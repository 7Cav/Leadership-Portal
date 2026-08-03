<?php

namespace App\Integration\Cav;

use RuntimeException;
use Throwable;

/**
 * A Cav roster API call failed or returned an unusable payload. Thrown from the Integration
 * layer; EnrichCavProfile (a CommandHandler) catches it and rethrows a CommandHandler-layer
 * `CavEnrichmentException` the controller can act on — the controller must not depend on this
 * Integration-layer type directly (Deptrac). No FQCN reference here on purpose: Pint would
 * promote it to a use-import and create Integration → Commands.
 *
 * Every failure carries a {@see CavFailureReason}, so callers decide how loudly to record it
 * from a discriminator rather than by matching message text.
 */
class CavRequestException extends RuntimeException
{
    private function __construct(
        public readonly CavFailureReason $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function profileFetchFailed(int $cavId, int $status): self
    {
        return new self(
            CavFailureReason::HttpError,
            "Cav milpac profile fetch failed for cav id {$cavId} (HTTP {$status}).",
        );
    }

    public static function rosterFetchFailed(string $roster, int $status): self
    {
        return new self(
            CavFailureReason::HttpError,
            "Cav roster fetch failed for {$roster} (HTTP {$status}).",
        );
    }

    public static function notOnRoster(int $forumUserId): self
    {
        return new self(
            CavFailureReason::NotOnRoster,
            "No Cav roster record carries forum user id {$forumUserId}.",
        );
    }

    /**
     * The sought trooper *was* found on a roster, but their entry could not be parsed. Kept
     * distinct from {@see notOnRoster} because the two point at opposite conclusions: this one
     * means the roster does carry them, so reporting "no milpac" would send whoever debugs it
     * after the wrong system.
     */
    public static function rosterEntryUnusable(int $forumUserId, int|string $cavIdKey): self
    {
        return new self(
            CavFailureReason::RecordUnusable,
            "The Cav roster entry for forum user id {$forumUserId} (cav id {$cavIdKey}) could not be parsed.",
        );
    }

    public static function malformedProfile(string $detail): self
    {
        return new self(CavFailureReason::Malformed, "Cav milpac profile was unusable: {$detail}.");
    }

    public static function malformedRoster(string $detail): self
    {
        return new self(CavFailureReason::Malformed, "Cav roster response was unusable: {$detail}.");
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self(
            CavFailureReason::Unreachable,
            'The Cav roster API could not be reached.',
            $previous,
        );
    }

    public static function malformedResponse(Throwable $previous): self
    {
        return new self(
            CavFailureReason::Malformed,
            'The Cav roster API returned a response that could not be parsed.',
            $previous,
        );
    }

    public static function missingCredential(): self
    {
        return new self(
            CavFailureReason::MissingCredential,
            'No Cav roster API key is configured (CAV_API_KEY).',
        );
    }

    public static function unusableBaseUrl(string $baseUrl): self
    {
        return new self(
            CavFailureReason::Unreachable,
            "The configured Cav roster API base URL is unusable ('{$baseUrl}').",
        );
    }

    /**
     * The sweep ran out of its wall-clock budget. Distinct from a per-request timeout: it is
     * the *aggregate* that matters on a synchronous login path.
     */
    public static function sweepBudgetExhausted(int $forumUserId, float $seconds): self
    {
        return new self(
            CavFailureReason::Unreachable,
            sprintf('The Cav roster sweep for forum user id %d exceeded its %.1fs budget.', $forumUserId, $seconds),
        );
    }
}
