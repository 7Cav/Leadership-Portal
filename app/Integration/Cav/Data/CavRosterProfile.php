<?php

namespace App\Integration\Cav\Data;

use App\Integration\Cav\CavRequestException;
use DateTimeImmutable;

/**
 * A trooper's Cav-authoritative roster context (AD-5): rank, billet and MOS as api.7cav.us
 * reports them. The portal reads these and asserts none of them itself.
 *
 * Three id spaces meet in this DTO, and conflating them is the bug this class exists to
 * prevent:
 *  - `cavId` — the **milpac relation id**. It is the `profile/id/{…}` path value and the key
 *    of the roster `profiles` map. It is *not* in the response body, so it always arrives
 *    from the caller.
 *  - `forumUserId` — the body's `user.userId`, which is the **forum** user id (verified live:
 *    `profile/id/3` → `userId: 9` → `7cav.us/members/9/` is `Kleinman.H`). This is what
 *    `users.forum_user_id` holds, so it is the field identity is matched on.
 *  - `username` — display/diagnostics only. Never used to match a trooper (AC #6).
 *
 * `fetchedAt` is stamped by the seam at fetch time and travels with the values, so whatever
 * renders them can show their source and age (AD-20, NFR7).
 *
 * **Strict on identity, lenient on display.** The two ids must be exact non-negative integers
 * or parsing fails — a schema drift there would silently link the wrong trooper. Every other
 * field degrades to null, because a drift in `mos` should cost a leader their MOS badge, not
 * their whole roster context. `username` is deliberately in the lenient group: identity never
 * depends on it, so an empty one must not make a trooper unenrichable.
 *
 * The API's wire conventions are normalized on the way in: an unset object arrives as `null`,
 * an unset string as `''`, and 64-bit ids as decimal strings.
 */
readonly class CavRosterProfile
{
    public function __construct(
        public int $cavId,
        public int $forumUserId,
        public DateTimeImmutable $fetchedAt,
        public ?string $username = null,
        public ?string $rankShort = null,
        public ?string $rankFull = null,
        public ?string $billet = null,
        public ?string $mos = null,
        public ?RosterType $roster = null,
    ) {
        if ($cavId <= 0) {
            throw CavRequestException::malformedProfile("cav id {$cavId} is not a positive integer");
        }

        if ($forumUserId <= 0) {
            throw CavRequestException::malformedProfile("forum user id {$forumUserId} is not a positive integer");
        }
    }

    /**
     * Build from a `Profile`/`LiteProfile` body. `$cavId` comes from the caller because the
     * milpac relation id is not part of the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(int $cavId, array $payload, DateTimeImmutable $fetchedAt): self
    {
        // The API answers some failures with a 200 and a `{code, message, details}` body rather
        // than an error status, so a missing `user` envelope is the real check.
        $user = $payload['user'] ?? null;

        if (! is_array($user)) {
            throw CavRequestException::malformedProfile('the response carried no `user` envelope');
        }

        $forumUserId = self::id($user['userId'] ?? null);

        if ($forumUserId === null) {
            throw CavRequestException::malformedProfile(
                'the `user.userId` field was not an integer id: '.var_export($user['userId'] ?? null, true),
            );
        }

        $rank = is_array($payload['rank'] ?? null) ? $payload['rank'] : [];
        $primary = is_array($payload['primary'] ?? null) ? $payload['primary'] : [];

        return new self(
            cavId: $cavId,
            forumUserId: $forumUserId,
            fetchedAt: $fetchedAt,
            username: self::text($user['username'] ?? null),
            rankShort: self::text($rank['rankShort'] ?? null),
            rankFull: self::text($rank['rankFull'] ?? null),
            billet: self::text($primary['positionTitle'] ?? null),
            mos: self::text($payload['mos'] ?? null),
            // An unrecognised roster type — including the API's explicit `*_UNSPECIFIED`
            // member, and any roster added later — carries no more than null does.
            roster: RosterType::tryFrom((string) self::text($payload['roster'] ?? null)),
        );
    }

    /**
     * Find the entry whose forum user id matches, and return it keyed by its map key — which
     * *is* the trooper's `cav_id`. This is the only way to learn a milpac relation id from the
     * API, and it is an exact id match: a username is never compared (AC #6).
     *
     * `$onSkip` is invoked for every entry that could not be parsed, so a skip leaves a trace
     * instead of being indistinguishable from a clean miss. Skipping one bad row out of ~800
     * must not deny every leader their roster context, but it must not be silent either.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(int|string, string): void  $onSkip  given the map key and the reason
     *
     * @throws CavRequestException when the response is not a roster document, or when the
     *                             sought trooper *was* present but their entry was unusable
     */
    public static function findInRoster(
        array $payload,
        int $forumUserId,
        DateTimeImmutable $fetchedAt,
        ?callable $onSkip = null,
    ): ?self {
        // Normalised rather than nullsafe-invoked: `$onSkip()` is not valid PHP for a
        // closure held in a variable.
        $onSkip ??= static fn (int|string $cavIdKey, string $reason) => null;

        $profiles = $payload['profiles'] ?? null;

        if (! is_array($profiles)) {
            throw CavRequestException::malformedRoster('it carried no `profiles` map');
        }

        // A JSON *list* would pass `is_array` while its keys were 0,1,2… — array offsets that
        // look like valid milpac ids. Taking one as a `cav_id` would persist a fabricated
        // identity link and strip it from whoever legitimately holds that id, and the rendered
        // rank would still look right because the forum-id match would hold. Reject outright.
        if ($profiles !== [] && array_is_list($profiles)) {
            throw CavRequestException::malformedRoster('its `profiles` field was a list, not a map keyed by milpac id');
        }

        // Identity is read before the milpac id on purpose. Once an entry is known to be *this*
        // trooper's, a problem with it can no longer be skipped quietly: continuing would let the
        // sweep finish and conclude "no milpac", which asserts the opposite of what the roster
        // actually says and sends whoever debugs it after the wrong system.
        foreach ($profiles as $cavIdKey => $entry) {
            if (! is_array($entry)) {
                $onSkip($cavIdKey, 'the entry was not an object');

                continue;
            }

            $entryForumUserId = is_array($entry['user'] ?? null)
                ? self::id($entry['user']['userId'] ?? null)
                : null;

            if ($entryForumUserId === null) {
                $onSkip($cavIdKey, 'the entry carried no usable `user.userId`');

                continue;
            }

            if ($entryForumUserId !== $forumUserId) {
                continue;
            }

            $cavId = self::id($cavIdKey);

            if ($cavId === null) {
                $onSkip($cavIdKey, 'the map key was not an integer milpac id');

                throw CavRequestException::rosterEntryUnusable($forumUserId, $cavIdKey);
            }

            // Cannot throw from here: the identity fields are already parsed and `$cavId` is a
            // positive integer, and every remaining field is lenient by design.
            return self::fromPayload($cavId, $entry, $fetchedAt);
        }

        return null;
    }

    /**
     * Parse an id the strict way. The API sends 64-bit ids as decimal strings, so only digits
     * are acceptable — `is_numeric` would admit `'4.9'` and `'1e3'`, and the `(int)` cast would
     * then quietly truncate them into a *different, valid* trooper's id. For values that decide
     * which human being's record is used, "close enough" is the whole failure mode.
     */
    private static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || $value === '' || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }

    /**
     * The API sends an unset string as `''`; carry that through as null so callers do not have
     * to distinguish "absent" from "empty".
     */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
