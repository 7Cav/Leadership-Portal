<?php

namespace App\Commands\PortalRoleGrants;

use App\Models\PortalRoleGrant;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Set, change or clear a staff grant's end date (audited, reason required, AD-16). Admin-only, enforced by the
 * controller (AD-6), like {@see GrantStaffScope}. Setting a date in the past is refused by the controller's validation,
 * so a grant is never ended by an edit; that is what {@see RevokeStaffScope} is for. Saving the date it already has is a
 * no-op with no audit entry.
 */
class SetStaffGrantExpiry
{
    public function __construct(
        private readonly PortalRoleGrantRepository $grants,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(User $actor, PortalRoleGrant $grant, ?GrantExpiry $expiry, ?string $reason): ?PortalRoleGrant
    {
        $unchanged = $expiry === null
            ? $grant->expires_at === null
            : $grant->expires_at?->equalTo($expiry->expiresAt) && $grant->renew_every_days === $expiry->renewEveryDays;

        if ($unchanged) {
            return $grant;
        }

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'portal_role_grant.expiry_set',
                reason: $reason,
                reasonRequired: true,
                mutation: fn () => $this->grants->setExpiry($grant->id, $expiry?->expiresAt, $expiry?->renewEveryDays, $actor->id)
                    ?? throw new GrantUnchangedException(null),
            );
        } catch (GrantUnchangedException) {
            return null;
        }
    }
}
