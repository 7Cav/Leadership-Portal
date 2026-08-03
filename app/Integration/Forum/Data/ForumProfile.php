<?php

namespace App\Integration\Forum\Data;

use App\Integration\Forum\ForumRequestException;

/**
 * The forum identity returned by `GET /api/me`. Carries only what the read-only
 * scope exposes: the forum user id (the identity key), username and (optionally)
 * email. Rank, billet, MOS and `cav_id` are NOT here — they come from the Cav
 * roster API in Story 1.6.
 *
 * The identity invariants (a positive forum user id, a non-empty username) are
 * enforced at construction, so no valid instance can key a row on a bogus id.
 */
readonly class ForumProfile
{
    public function __construct(
        public int $forumUserId,
        public string $username,
        public ?string $email = null,
    ) {
        if ($forumUserId <= 0 || $username === '') {
            throw ForumRequestException::malformedProfile();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        // XenForo wraps the current user in a `me` envelope; tolerate a flat shape too.
        $me = is_array($data['me'] ?? null) ? $data['me'] : $data;

        $userId = $me['user_id'] ?? null;
        $username = $me['username'] ?? null;

        if (! is_numeric($userId) || ! is_string($username)) {
            throw ForumRequestException::malformedProfile();
        }

        $email = $me['email'] ?? null;

        return new self(
            forumUserId: (int) $userId,
            username: $username,
            email: is_string($email) && $email !== '' ? $email : null,
        );
    }
}
