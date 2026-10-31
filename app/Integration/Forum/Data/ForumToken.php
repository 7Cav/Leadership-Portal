<?php

namespace App\Integration\Forum\Data;

use App\Integration\Forum\ForumRequestException;

/**
 * The OAuth2 access token returned by the forum token endpoint, with its lifetime when the forum gives one. Never
 * persisted to the database (AD-6) and never refreshed. The login handshake holds it in memory only; the PM consent
 * (#565) hands it to the session, encrypted, until it expires. The non-empty invariant is enforced at construction, so
 * no valid instance can exist without a usable token.
 */
readonly class ForumToken
{
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        public ?int $expiresIn = null,
    ) {
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

        $expiresIn = $data['expires_in'] ?? null;

        return new self($accessToken, is_int($expiresIn) && $expiresIn > 0 ? $expiresIn : null);
    }
}
