<?php

namespace App\Commands\Auth;

use Carbon\CarbonImmutable;

/**
 * What the PM consent leaves the session (#565): the user's forum access token, able to send PMs from their account,
 * and when it stops serving. Held encrypted in the session only, never in the database (AD-6, AD-23). A token the forum
 * gave no lifetime is kept for {@see DEFAULT_HOURS} hours.
 */
final readonly class ForumPmGrant
{
    public const DEFAULT_HOURS = 1;

    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        public CarbonImmutable $expiresAt,
    ) {}

    public function isLive(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->lessThan($this->expiresAt);
    }

    /** @return array{token: string, expires_at: string} */
    public function toArray(): array
    {
        return ['token' => $this->accessToken, 'expires_at' => $this->expiresAt->toIso8601String()];
    }

    /** The grant from what {@see toArray()} wrote, or null for anything else. */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['token'] ?? null) || $data['token'] === '' || ! is_string($data['expires_at'] ?? null)) {
            return null;
        }

        try {
            return new self($data['token'], CarbonImmutable::parse($data['expires_at']));
        } catch (\Throwable) {
            return null;
        }
    }
}
