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
     * the row that still holds it. Clearing the stale holder first keeps the link single-valued
     * and lets that user re-resolve on their next login, which is the same path a first-time
     * user takes.
     *
     * Both statements run in one transaction because they are a single invariant ("exactly one
     * row holds this milpac id"): a failure between them would leave the previous holder unlinked
     * with no replacement written, which is pure data loss.
     *
     * What the transaction does **not** do is make concurrent logins collision-free, and it is
     * worth being precise since a future reader will trust this comment. The `FOR UPDATE` takes a
     * record lock only when a row actually holds the id, which correctly serializes the
     * *displacement* case. When no row holds it, InnoDB takes a gap lock instead, and gap locks
     * are mutually compatible — so two logins resolving the same new id can both pass the SELECT
     * and then deadlock on the insert-intention lock. Either way the failure surfaces as a
     * `QueryException` from inside the callback and is translated below, so the login degrades
     * rather than 500s; the race is narrowed and made survivable, not eliminated.
     *
     * The unlink is logged because it *destroys* a portal-owned link on the strength of an
     * external payload. When Epic 2's sync or a later scope decision reads that null, this line
     * is the only thing that will explain it.
     *
     * A write failure leaves as an {@see IdentityLinkException}: the DB layer is reachable only
     * from here (AD-2), so a `QueryException` must not escape into a CommandHandler — Deptrac
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
