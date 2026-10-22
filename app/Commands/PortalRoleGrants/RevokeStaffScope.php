<?php

namespace App\Commands\PortalRoleGrants;

use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Detach a staff user's explicit grant from a unit node. A no-op when no grant exists never
 * routes through {@see AuditEnvelope} — work that didn't happen doesn't get logged, so a
 * double-click never records a second "portal_role_grant.revoked" for the same node. That holds
 * for two revokes racing past the check too: the repository reads the grant under a lock, and the
 * one that finds it gone rolls back without an entry (see {@see GrantUnchangedException}).
 * Admin-only — enforced by the controller (AD-6), same pattern as {@see GrantStaffScope}. The
 * affected user's effective scope (`Authorization\ScopeResolver`, Story 1.11) reflects the
 * removal on their very next request — the resolver holds no cache to invalidate.
 */
class RevokeStaffScope
{
    public function __construct(
        private readonly PortalRoleGrantRepository $grants,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(User $actor, User $target, Unit $unit, ?string $reason): ?PortalRoleGrant
    {
        if ($this->grants->find($target, $unit) === null) {
            return null;
        }

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'portal_role_grant.revoked',
                reason: $reason,
                reasonRequired: true,
                // A racing revoke that committed between the check above and this write leaves
                // nothing to remove: the same no-op as above, not an audited revoke of nothing.
                mutation: fn () => $this->grants->revoke($target, $unit) ?? throw new GrantUnchangedException(null),
            );
        } catch (GrantUnchangedException) {
            return null;
        }
    }
}
