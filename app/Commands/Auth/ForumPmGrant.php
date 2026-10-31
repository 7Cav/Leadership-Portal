<?php

namespace App\Commands\Auth;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * What the PM consent leaves the session (#565): the forum access token that sends PMs from one person's own account,
 * whose forum account it is (`$forumUserId`, checked at consent against the person really signed in), and when it
 * stops serving. Held encrypted in the session only, never in the database (AD-6, AD-23). A grant serves only the person
 * whose account it is ({@see servesForumUser()}): an Admin acting as someone sends from their own account, never from the
 * other person's, and the record names who acted. A token the forum gave no lifetime is kept for {@see DEFAULT_HOURS}.
 */
final readonly class ForumPmGrant
{
    public const DEFAULT_HOURS = 1;

    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        public CarbonImmutable $expiresAt,
        public int $forumUserId,
    ) {
        if ($accessToken === '' || $forumUserId < 1) {
            throw new InvalidArgumentException('A PM grant needs a token and the forum account it belongs to.');
        }
    }

    public function isLive(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->lessThan($this->expiresAt);
    }

    /** Whether this grant is the forum account `$forumUserId`'s. */
    public function servesForumUser(?int $forumUserId): bool
    {
        return $forumUserId !== null && $forumUserId === $this->forumUserId;
    }

    /** @return array{token: string, expires_at: string, forum_user_id: int} */
    public function toArray(): array
    {
        return ['token' => $this->accessToken, 'expires_at' => $this->expiresAt->toIso8601String(), 'forum_user_id' => $this->forumUserId];
    }

    /** The grant from what {@see toArray()} wrote, or null for anything else. */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['token'] ?? null) || ! is_string($data['expires_at'] ?? null) || ! is_int($data['forum_user_id'] ?? null)) {
            return null;
        }

        try {
            return new self($data['token'], CarbonImmutable::parse($data['expires_at']), $data['forum_user_id']);
        } catch (Throwable) {
            return null;
        }
    }
}
