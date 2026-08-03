<?php

namespace App\Integration\Cav\Data;

use App\Integration\Cav\CavRequestException;
use DateTimeImmutable;

/**
 * A trooper's Cav-authoritative roster context (AD-5): rank, billet and MOS as
 * api.7cav.us reports them. The portal reads these and asserts none of them itself.
 *
 * Three id spaces meet in this DTO, and conflating them is the bug this class exists
 * to prevent:
 *  - `cavId` — the **milpac relation id**. It is the `profile/id/{…}` path value and
 *    the key of the roster `profiles` map. It is *not* in the response body, so it
 *    always arrives from the caller.
 *  - `forumUserId` — the body's `user.userId`, which is the **forum** user id (verified
 *    live: `profile/id/3` → `userId: 9` → `7cav.us/members/9/` is `Kleinman.H`). This is
 *    what `users.forum_user_id` holds, so it is the field identity is matched on.
 *  - `username` — carried for display only. Never used to match a trooper (AC #6).
 *
 * `fetchedAt` is stamped by the seam at fetch time and travels with the values, so
 * whatever renders them can show their source and age (AD-20, NFR7).
 *
 * The API's wire conventions are normalized on the way in: an unset object arrives as
 * `null`, an unset string as `''`, and 64-bit ids as decimal strings.
 */
readonly class CavRosterProfile
{
    public function __construct(
        public int $cavId,
        public int $forumUserId,
        public string $username,
        public DateTimeImmutable $fetchedAt,
        public ?string $rankShort = null,
        public ?string $rankFull = null,
        public ?string $billet = null,
        public ?string $mos = null,
        public ?string $roster = null,
        public ?string $realName = null,
    ) {
        if ($cavId <= 0 || $forumUserId <= 0 || $username === '') {
            throw CavRequestException::malformedProfile();
        }
    }

    /**
     * Build from a `Profile`/`LiteProfile` body. `$cavId` comes from the caller because
     * the milpac relation id is not part of the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(int $cavId, array $payload, DateTimeImmutable $fetchedAt): self
    {
        $profile = self::tryFromPayload($cavId, $payload, $fetchedAt);

        if ($profile === null) {
            throw CavRequestException::malformedProfile();
        }

        return $profile;
    }

    /**
     * Find the entry whose forum user id matches, and return it keyed by its map key —
     * which *is* the trooper's `cav_id`. This is the only way to learn a milpac relation
     * id from the API, and it is an exact id match: a username is never compared (AC #6).
     *
     * A single unparseable entry is skipped rather than failing the sweep — one bad row
     * out of ~800 must not deny every leader their roster context. A response with no
     * `profiles` map at all is a different matter and throws, because it means the call
     * did not return a roster.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function findInRoster(array $payload, int $forumUserId, DateTimeImmutable $fetchedAt): ?self
    {
        if (! is_array($payload['profiles'] ?? null)) {
            throw CavRequestException::malformedRoster();
        }

        foreach ($payload['profiles'] as $cavId => $entry) {
            // The map key is the milpac relation id; anything non-numeric is not one.
            if (! is_numeric($cavId) || ! is_array($entry)) {
                continue;
            }

            $profile = self::tryFromPayload((int) $cavId, $entry, $fetchedAt);

            if ($profile?->forumUserId === $forumUserId) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function tryFromPayload(int $cavId, array $payload, DateTimeImmutable $fetchedAt): ?self
    {
        // The API answers some failures with a 200 and a `{code, message, details}` body
        // rather than an error status, so a missing `user` envelope is the real check.
        $user = $payload['user'] ?? null;

        if (! is_array($user) || ! is_numeric($user['userId'] ?? null) || ! is_string($user['username'] ?? null)) {
            return null;
        }

        $rank = is_array($payload['rank'] ?? null) ? $payload['rank'] : [];
        $primary = is_array($payload['primary'] ?? null) ? $payload['primary'] : [];
        $roster = self::text($payload['roster'] ?? null);

        try {
            return new self(
                cavId: $cavId,
                forumUserId: (int) $user['userId'],
                username: $user['username'],
                fetchedAt: $fetchedAt,
                rankShort: self::text($rank['rankShort'] ?? null),
                rankFull: self::text($rank['rankFull'] ?? null),
                billet: self::text($primary['positionTitle'] ?? null),
                mos: self::text($payload['mos'] ?? null),
                // The enum's explicit "unset" member is no more informative than null.
                roster: $roster === 'ROSTER_TYPE_UNSPECIFIED' ? null : $roster,
                realName: self::text($payload['realName'] ?? null),
            );
        } catch (CavRequestException) {
            return null;
        }
    }

    /**
     * The API sends an unset string as `''`; carry that through as null so callers do
     * not have to distinguish "absent" from "empty".
     */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
