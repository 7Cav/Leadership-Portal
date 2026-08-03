<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * Link the trooper's milpac relation id (Story 1.6). This is the one roster-derived value
     * the portal owns — an identity link, not a roster fact (AD-22).
     *
     * `cav_id` is unique, so a milpac id Cav has reassigned to someone else would collide with
     * the row that still holds it. Clearing the stale holder first keeps the link single-valued
     * and lets that user re-resolve on their next login, which is the same path a first-time
     * user takes.
     *
     * Both statements run in one transaction: they are a single invariant ("exactly one row
     * holds this milpac id"), and a failure between them would leave the previous holder
     * unlinked with no replacement written — pure data loss. It also closes the window where
     * two concurrent logins resolving the same id both see zero rows to clear and then both
     * try to claim it.
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
    public function linkCavId(User $user, int $cavId): User
    {
        try {
            $this->writeCavIdLink($user, $cavId);
        } catch (QueryException $e) {
            throw IdentityLinkException::cavIdNotLinked((int) $user->getKey(), $cavId, $e);
        }

        return $user;
    }

    private function writeCavIdLink(User $user, int $cavId): void
    {
        DB::transaction(function () use ($user, $cavId) {
            $displaced = User::where('cav_id', $cavId)
                ->whereKeyNot($user->getKey())
                ->lockForUpdate()
                ->pluck('id');

            if ($displaced->isNotEmpty()) {
                Log::warning('Reassigning a Cav milpac id; clearing it from its previous holder.', [
                    'cav_id' => $cavId,
                    'from_user_ids' => $displaced->all(),
                    'to_user_id' => $user->getKey(),
                ]);

                User::whereIn('id', $displaced)->update(['cav_id' => null]);
            }

            $user->update(['cav_id' => $cavId]);
        });
    }
}
