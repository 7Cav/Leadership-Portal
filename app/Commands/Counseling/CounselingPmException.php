<?php

namespace App\Commands\Counseling;

use RuntimeException;
use Throwable;

/**
 * A counseling PM could not be sent (#565), so the step that needed it did not happen and the record is as it was.
 * `$needsConsent`: the user has to let the portal send PMs from their forum account first (no grant in the session, it
 * expired, or the forum no longer accepts the token). Every message is written here and names no token.
 *
 * Only a refusal the forum stated plainly (a 4xx) is certain not to have been delivered. A timeout, a server error or an
 * answer the portal could not read may have come after the forum stored the PM, so those say to check the forum's
 * conversations before trying again: a retry would otherwise send the trooper the same PM twice.
 */
class CounselingPmException extends RuntimeException
{
    /** Forum codes that mean the token itself no longer serves, so asking again can help. */
    private const TOKEN_CODES = ['invalid_token', 'expired_token', 'invalid_grant', 'no_api_key_in_request', 'invalid_scope'];

    /**
     * @param  bool  $mayHaveSent  the forum timed out, failed or answered unreadably: the PM may have gone all the same
     */
    public function __construct(string $message, public readonly bool $needsConsent = false, ?Throwable $previous = null, public readonly bool $mayHaveSent = false)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function consentNeeded(): self
    {
        return new self('Let the portal send PMs from your forum account first.', needsConsent: true);
    }

    public static function noForumAccount(string $who): self
    {
        return new self("{$who} has no forum account the portal knows, so the PM cannot be sent. Nothing was changed.");
    }

    public static function refused(?int $status, ?string $forumCode, Throwable $previous): self
    {
        if ($status === 401 || in_array($forumCode, self::TOKEN_CODES, true)) {
            return new self('The forum no longer accepts the portal sending PMs for you. Allow it again.', needsConsent: true, previous: $previous);
        }

        $code = $forumCode === null ? '' : ", {$forumCode}";

        if ($status === 403) {
            return new self("The forum refused the PM (HTTP 403{$code}): your forum account may not start conversations, or the recipient does not accept them. Nothing was changed.", previous: $previous);
        }

        if ($status !== null && $status >= 400 && $status < 500) {
            return new self("The forum refused the PM (HTTP {$status}{$code}). Nothing was changed.", previous: $previous);
        }

        $why = $status === null ? 'the forum did not answer in time, or answered in a way the portal could not read' : "the forum answered HTTP {$status}";

        return new self("The PM may or may not have been sent: {$why}. Nothing was changed in the portal. Check your forum conversations before trying again, so it is not sent twice.", previous: $previous, mayHaveSent: true);
    }
}
