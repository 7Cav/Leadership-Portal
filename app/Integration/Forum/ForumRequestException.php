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
    /** The HTTP status the forum answered with, when it answered. */
    public ?int $httpStatus = null;

    /**
     * Whether retrying cannot help: the forum said no (forbidden, not found, gone) or the thread is not
     * what was asked for. A temporary failure (unreachable, a 5xx) is not permanent, so a caller retries it.
     */
    public bool $permanent = false;

    public function isPermanent(): bool
    {
        return $this->permanent || in_array($this->httpStatus, [403, 404, 410], true);
    }

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

    public static function threadListFailed(int $status, ?string $code = null): self
    {
        $exception = new self("Forum thread list failed (HTTP {$status}".($code === null ? '' : ", {$code}").').');
        $exception->httpStatus = $status;

        return $exception;
    }

    public static function threadFetchFailed(int $status, ?string $code = null): self
    {
        $exception = new self("Forum thread fetch failed (HTTP {$status}".($code === null ? '' : ", {$code}").').');
        $exception->httpStatus = $status;

        return $exception;
    }

    public static function malformedThread(): self
    {
        return new self('The forum returned a thread payload that was missing a field the portal needs.');
    }

    public static function apiKeyMissing(): self
    {
        return new self('No forum API key is configured, so the forum cannot be read. Set SEVENCAV_FORUM_API_KEY.');
    }

    public static function notALoaSection(int $nodeId): self
    {
        return new self("Forum section {$nodeId} is not a configured LOA section, so it is not read.");
    }

    public static function pafNotConfigured(): self
    {
        return new self('No PAF forum section is configured, so a PAF thread cannot be read. Set SEVENCAV_FORUM_PAF_NODE.');
    }

    public static function notAPafThread(int $nodeId): self
    {
        $exception = new self("Thread is in forum section {$nodeId}, not the configured PAF section, so it is not read.");
        $exception->permanent = true;

        return $exception;
    }

    public static function threadNotVisible(): self
    {
        $exception = new self('The thread is not visible (deleted or awaiting moderation).');
        $exception->permanent = true;

        return $exception;
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
