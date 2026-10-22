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
 * node. Two grants racing past that check meet at the unique key: the one that loses finds the
 * row the other wrote and rolls back without an entry (see {@see GrantUnchangedException}).
 * Otherwise the write is staged through `AuditEnvelope` (AD-16) with a reason always
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

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'portal_role_grant.granted',
                reason: $reason,
                reasonRequired: true,
                mutation: function () use ($target, $unit) {
                    $grant = $this->grants->grant($target, $unit);

                    // A racing grant for the same node committed between the check above and this
                    // write: the node is already granted, so this is the same no-op as above.
                    if (! $grant->wasRecentlyCreated) {
                        throw new GrantUnchangedException($grant);
                    }

                    return $grant;
                },
            );
        } catch (GrantUnchangedException $unchanged) {
            return $unchanged->grant;
        }
    }
}
