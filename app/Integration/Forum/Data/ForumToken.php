<?php

namespace App\Integration\Forum\Data;

use App\Integration\Forum\ForumRequestException;

/**
 * The OAuth2 access token returned by the forum token endpoint. Held only in
 * memory for the duration of the login handshake — never persisted (AD-6), never
 * refreshed — so it carries just the bearer credential it is used for. The
 * non-empty invariant is enforced at construction, so no valid instance can exist
 * without a usable token.
 */
readonly class ForumToken
{
    public function __construct(public string $accessToken)
    {
        if ($accessToken === '') {
            throw ForumRequestException::malformedToken();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $accessToken = $data['access_token'] ?? null;

        if (! is_string($accessToken)) {
            throw ForumRequestException::malformedToken();
        }

        return new self($accessToken);
    }
}
