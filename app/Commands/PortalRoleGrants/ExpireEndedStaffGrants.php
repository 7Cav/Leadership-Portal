<?php

namespace App\Commands\PortalRoleGrants;

use App\Repositories\PortalRoleGrantRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekLabel;
use Carbon\CarbonInterface;

/**
 * Revoke, for good, every grant set to end whose end date has passed (the scheduled `portal:expire-staff-grants`).
 * The grant already gave no scope from that moment (the repository leaves it out of the scope); this removes the row
 * on the record, with one audited `portal_role_grant.expired` entry per grant (AD-16). The console has no portal
 * identity, so the actor is whoever set the end date, or the holder when that user is gone, and the reason says it was
 * automatic. Grants that renew are kept: they lapse and wait for a renewal.
 */
class ExpireEndedStaffGrants
{
    public function __construct(
        private readonly PortalRoleGrantRepository $grants,
        private readonly AuditEnvelope $audit,
    ) {}

    /** @return int how many grants were revoked */
    public function handle(CarbonInterface $now): int
    {
        $revoked = 0;

        foreach ($this->grants->endedBefore($now) as $grant) {
            $label = WeekLabel::date($grant->expires_at);

            try {
                $this->audit->record(
                    actorId: $grant->expiry_set_by ?? $grant->user_id,
                    action: 'portal_role_grant.expired',
                    reason: "Ended automatically: the grant's end date, {$label}, passed.",
                    reasonRequired: true,
                    mutation: fn () => $this->grants->revokeById($grant->id) ?? throw new GrantUnchangedException(null),
                );
                $revoked++;
            } catch (GrantUnchangedException) {
                // Revoked by hand between the read and this write: nothing left to end.
            }
        }

        return $revoked;
    }
}
