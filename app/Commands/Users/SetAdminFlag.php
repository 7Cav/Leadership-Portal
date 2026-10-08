<?php

namespace App\Commands\Users;

use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Grant or revoke the Admin flag (AD-7) — the highest-authority mutation in the portal, so it
 * always routes through the audited envelope (AD-16) with a reason. A no-op (the user already
 * holds the requested state) never reaches the envelope, matching `GrantStaffScope`'s rule,
 * so re-running a bootstrap never logs a second grant.
 *
 * Today its only caller is `php artisan portal:admin` (story 2.9), which bootstraps the first
 * Admin before anyone can sign in as one. The console has no portal identity of its own, and
 * `audit_log.actor_id` must name a user, so that caller records the target as the actor; the
 * reason it passes says the change came from the console.
 */
class SetAdminFlag
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @return bool whether anything changed
     */
    public function handle(User $actor, User $target, bool $isAdmin, string $reason): bool
    {
        if ((bool) $target->is_admin === $isAdmin) {
            return false;
        }

        $this->audit->record(
            actorId: $actor->id,
            action: $isAdmin ? 'user.admin_granted' : 'user.admin_revoked',
            reason: $reason,
            reasonRequired: true,
            mutation: fn () => $this->users->setAdmin($target, $isAdmin),
        );

        return true;
    }
}
