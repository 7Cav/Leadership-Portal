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
}
