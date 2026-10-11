<?php

namespace App\Commands\Auth;

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavFailureReason;
use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\CavRosterProfile;
use App\Models\User;
use App\Repositories\IdentityLinkException;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a signed-in user's Cav roster context and links their milpac id (Story 1.6).
 *
 * Identity is matched on ids only, never on a name (AC #6). Which id depends on what the portal
 * already knows:
 *
 *  - **`cav_id` known** — one call to `profile/id/{cav_id}`, then a check that the record
 *    returned still reports our `forum_user_id`. Milpac relation ids are Cav's to reassign, and
 *    a stale one would otherwise render another trooper's rank as this leader's own.
 *  - **`cav_id` unknown** (every user after Story 1.4, which cannot fill it) — sweep the serving
 *    rosters for `forum_user_id`. The map key that matches *is* the milpac id, and it is
 *    persisted so this path runs once per trooper.
 *
 * Roster *facts* are never persisted: rank, billet and MOS are Cav-authoritative (AD-5) and the
 * portal asserts none of them. Only the identity link is portal-owned (AD-22); the `troopers`
 * cache and its single `Sync/` writer are Epic 2.
 */
class EnrichCavProfile
{
    public function __construct(
        private readonly CavApi $cav,
        private readonly UserRepository $users,
    ) {}

    /**
     * @throws CavEnrichmentException when no profile could be resolved or linked — the caller
     *                                carries on unenriched; whether the user is let in at all is
     *                                {@see AdmitServingMember}'s call, from the local roster cache
     */
    public function handle(User $user): CavRosterProfile
    {
        $forumUserId = (int) $user->forum_user_id;

        try {
            $profile = $this->resolve($user, $forumUserId);

            // Inside the guarded region on purpose: `cav_id` is unique, so this write can fail
            // on a concurrent login resolving the same milpac id. Left outside, that failure
            // would 500 a request where Auth::login() has already run.
            if ((int) $user->cav_id !== $profile->cavId) {
                $this->users->linkCavId($user, $profile->cavId);
            }
        } catch (CavRequestException $e) {
            throw new CavEnrichmentException(
                $e->reason,
                "Could not enrich the Cav roster profile for forum user {$forumUserId}: {$e->getMessage()}",
                previous: $e,
            );
        } catch (IdentityLinkException $e) {
            throw new CavEnrichmentException(
                CavFailureReason::LinkFailed,
                "Resolved the Cav roster profile for forum user {$forumUserId} but could not persist the milpac link.",
                previous: $e,
            );
        }

        return $profile;
    }

    private function resolve(User $user, int $forumUserId): CavRosterProfile
    {
        if ($user->cav_id === null) {
            return $this->cav->findOnRosterByForumUserId($forumUserId);
        }

        $storedCavId = (int) $user->cav_id;

        try {
            $profile = $this->cav->fetchProfileByCavId($storedCavId);

            if ($profile->forumUserId === $forumUserId) {
                return $profile;
            }

            $staleReason = "it now reports forum user id {$profile->forumUserId}";
        } catch (CavRequestException $e) {
            // A *missing* record — a 404/410, or the API's 200-with-`{code,message}` envelope — is
            // the same situation as a reassigned id: the stored link is wrong and re-resolving is
            // the only way back. Without this, a deleted milpac leaves `cav_id` stuck at the dead
            // value and the trooper unenriched on every future login.
            //
            // Anything else — 401 on a rotated key, 429, 503, an unreachable host — is evidence
            // about the *API*, not the link. Re-sweeping on it would cost three more doomed calls
            // and, worse, log this as a data-integrity event pointing at the wrong system.
            if (! $e->indicatesMissingRecord()) {
                throw $e;
            }

            $staleReason = $e->getMessage();
        }

        // A portal-owned identity link that no longer resolves to its owner is a data-integrity
        // event worth knowing about — and it silently costs this login a full roster sweep on top
        // of the profile call, so a systematic re-keying would otherwise surface only as
        // unexplained latency.
        Log::warning('Stored Cav milpac link is stale; re-resolving from the roster.', [
            'user_id' => $user->getKey(),
            'forum_user_id' => $forumUserId,
            'stored_cav_id' => $storedCavId,
            'reason' => $staleReason,
        ]);

        // Drop the link *before* re-resolving. It has just been established that this milpac id
        // does not belong to this trooper, and the re-sweep may well fail (a trooper whose milpac
        // was deleted is often no longer on any roster). Keeping a known-wrong link would leave
        // the row asserting someone else's identity — which Epic 2's sync will read and act on.
        $this->users->unlinkCavId($user);

        return $this->cav->findOnRosterByForumUserId($forumUserId);
    }
}
