<?php

namespace App\Repositories;

use App\Models\User;

/**
 * Sole DB access for the portal-owned identity link (AD-2).
 *
 * Takes scalars rather than the Integration-layer `ForumProfile` DTO on purpose:
 * Deptrac forbids Repository → Integration, so the CommandHandler unpacks the DTO
 * and hands the primitives here.
 */
class UserRepository
{
    /**
     * Create or update the identity row for a forum user, keyed on `forum_user_id`
     * so a re-login updates the existing row and never creates a duplicate.
     * `cav_id` is deliberately left untouched — roster enrichment (Story 1.6) owns it.
     */
    public function upsertFromForumIdentity(int $forumUserId, string $username, ?string $email): User
    {
        return User::updateOrCreate(
            ['forum_user_id' => $forumUserId],
            ['username' => $username, 'email' => $email],
        );
    }

    /**
     * Link the trooper's milpac relation id (Story 1.6). This is the one roster-derived
     * value the portal owns — an identity link, not a roster fact (AD-22).
     *
     * `cav_id` is unique, so a milpac id Cav has reassigned to someone else would collide
     * with the row that still holds it. Clearing the stale holder first keeps the link
     * single-valued and lets that user re-resolve on their next login, which is the same
     * path a first-time user takes.
     */
    public function linkCavId(User $user, int $cavId): User
    {
        User::where('cav_id', $cavId)
            ->whereKeyNot($user->getKey())
            ->update(['cav_id' => null]);

        $user->update(['cav_id' => $cavId]);

        return $user;
    }
}
