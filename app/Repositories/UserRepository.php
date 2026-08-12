<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sole DB access for the portal-owned identity link (AD-2).
 *
 * Takes scalars rather than the Integration-layer `ForumProfile` DTO on purpose:
 * Deptrac forbids Repository → Integration, so the CommandHandler unpacks the DTO
 * and hands the primitives here.
 */
interface UserRepository
{
    /**
     * Create or update the identity row for a forum user, keyed on `forum_user_id`
     * so a re-login updates the existing row and never creates a duplicate.
     * `cav_id` is deliberately left untouched — roster enrichment (Story 1.6) owns it.
     */
    public function upsertFromForumIdentity(int $forumUserId, string $username, ?string $email): User;

    /**
     * Link the trooper's milpac relation id (Story 1.6). This is the one roster-derived value
     * the portal owns — an identity link, not a roster fact (AD-22).
     *
     * `cav_id` is unique, so a milpac id Cav has reassigned to someone else would collide with
     * the row that still holds it: implementations must clear the stale holder first, keeping
     * the link single-valued, and let that user re-resolve on their next login — the same path
     * a first-time user takes. The clear-and-set is a single invariant ("exactly one row holds
     * this milpac id"), so it must happen atomically: a failure partway through must leave no
     * holder unlinked with no replacement written.
     *
     * A write failure surfaces as an {@see IdentityLinkException}: the DB layer is reachable
     * only from here (AD-2), so nothing DB-shaped may escape into a CommandHandler — Deptrac
     * fails the build if it does, and the caller could not catch it to degrade the login anyway.
     *
     * @throws IdentityLinkException when the link could not be written
     */
    public function linkCavId(User $user, int $cavId): User;

    /**
     * Drop the trooper's milpac link (Story 1.6). Used when the stored id has been shown not to
     * belong to them, so the row stops asserting an identity the portal knows to be wrong. Their
     * next login re-resolves from scratch, which is the same path a first-time user takes.
     */
    public function unlinkCavId(User $user): User;

    public function find(int $id): ?User;

    /**
     * Every user who is not an Admin, for the staff-grants target picker (Story 1.12). No
     * roster/Trooper model exists yet (Epic 2), so this is the full pool of portal-known
     * identities minus the ones a grant would be meaningless for — an Admin already resolves
     * the full tree (AD-6, AD-7).
     *
     * @return Collection<int, User>
     */
    public function nonAdminUsers(): Collection;
}
