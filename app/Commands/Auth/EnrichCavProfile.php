<?php

namespace App\Commands\Auth;

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\CavRosterProfile;
use App\Models\User;
use App\Repositories\UserRepository;

/**
 * Resolves a signed-in user's Cav roster context and links their milpac id (Story 1.6).
 *
 * Identity is matched on ids only, never on a name (AC #6). Which id depends on what the
 * portal already knows:
 *
 *  - **`cav_id` known** — one call to `profile/id/{cav_id}`, then a check that the record
 *    returned still reports our `forum_user_id`. Milpac relation ids are Cav's to reassign,
 *    and a stale one would otherwise render another trooper's rank as this leader's own.
 *  - **`cav_id` unknown** (every user after Story 1.4, which cannot fill it) — sweep the
 *    serving rosters for `forum_user_id`. The map key that matches *is* the milpac id, and
 *    it is persisted so this path runs once per trooper.
 *
 * Roster *facts* are never persisted: rank, billet and MOS are Cav-authoritative (AD-5) and
 * the portal asserts none of them. Only the identity link is portal-owned (AD-22); the
 * `troopers` cache and its single `Sync/` writer are Epic 2.
 */
class EnrichCavProfile
{
    public function __construct(
        private readonly CavApi $cav,
        private readonly UserRepository $users,
    ) {}

    /**
     * @throws CavEnrichmentException when no profile could be resolved — the caller signs
     *                                the user in unenriched rather than failing the login
     */
    public function handle(User $user): CavRosterProfile
    {
        $forumUserId = (int) $user->forum_user_id;

        try {
            $profile = $this->resolve($user, $forumUserId);
        } catch (CavRequestException $e) {
            throw new CavEnrichmentException(
                "Could not enrich the Cav roster profile for forum user {$forumUserId}.",
                previous: $e,
            );
        }

        if ((int) $user->cav_id !== $profile->cavId) {
            $this->users->linkCavId($user, $profile->cavId);
        }

        return $profile;
    }

    private function resolve(User $user, int $forumUserId): CavRosterProfile
    {
        if ($user->cav_id !== null) {
            $profile = $this->cav->fetchProfileByCavId((int) $user->cav_id);

            if ($profile->forumUserId === $forumUserId) {
                return $profile;
            }
            // Fall through: the stored id no longer belongs to this trooper, so re-resolve
            // from scratch rather than trust it or fail.
        }

        return $this->cav->findOnRosterByForumUserId($forumUserId);
    }
}
