<?php

namespace App\Commands\PortalRoleGrants;

use App\Commands\Units\CreateUnit;
use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Attach a non-admin staff user to a unit node (AD-7, AD-8) — explicit scope the roster can't
 * imply (S-shop staff, dual-hats). Admin-only — enforced by the controller (AD-6), same pattern
 * as {@see CreateUnit}. Refuses an admin target: see
 * {@see GrantTargetIsAdminException}. A no-op when the grant already exists never routes through
 * {@see AuditEnvelope} — the same rule {@see RevokeStaffScope} states for its own no-op, so a
 * double-click on "Grant scope" never records a second `portal_role_grant.granted` for the same
 * node. Otherwise the write is staged through `AuditEnvelope` (AD-16) with a reason always
 * required — a role grant is the kind of high-authority mutation CONTRIBUTING §4 reserves that
 * for.
 */
class GrantStaffScope
{
    public function __construct(
        private readonly PortalRoleGrantRepository $grants,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws GrantTargetIsAdminException when `$target` already holds the admin flag.
     */
    public function handle(User $actor, User $target, Unit $unit, ?string $reason): PortalRoleGrant
    {
        if ($target->is_admin) {
            throw GrantTargetIsAdminException::forUser($target);
        }

        $existing = $this->grants->find($target, $unit);

        if ($existing !== null) {
            return $existing;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'portal_role_grant.granted',
            reason: $reason,
            reasonRequired: true,
            mutation: fn () => $this->grants->grant($target, $unit),
        );
    }
}
