<?php

namespace App\Commands\PortalRoleGrants;

use App\Models\PortalRoleGrant;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use App\Support\Audit\AuditEnvelope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Renew a grant that lapses at its date (audited, reason required, AD-16), restoring it at once if it had lapsed.
 * Without a new date it moves on by its period, counted from its date or from today when it has already lapsed, so
 * a late renewal does not leave it lapsed again. A new date also becomes the new period. Admin-only, enforced by the
 * controller (AD-6), the same permission as editing a grant.
 */
class RenewStaffGrant
{
    public function __construct(
        private readonly PortalRoleGrantRepository $grants,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws GrantNotRenewableException when the grant has no renewal period.
     */
    public function handle(User $actor, PortalRoleGrant $grant, ?string $endsOn, ?string $reason, CarbonInterface $now): ?PortalRoleGrant
    {
        if (! $grant->renews()) {
            throw new GrantNotRenewableException;
        }

        $expiry = $endsOn !== null ? GrantExpiry::fromInput('renew', $endsOn, $now) : null;
        $today = CarbonImmutable::instance($now)->utc()->startOfDay();
        $from = $grant->expires_at->startOfDay()->max($today);
        $expiresAt = $expiry?->expiresAt ?? $from->addDays($grant->renew_every_days)->setTime(23, 59, 59);
        $period = $expiry?->renewEveryDays ?? $grant->renew_every_days;

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'portal_role_grant.renewed',
                reason: $reason,
                reasonRequired: true,
                mutation: fn () => $this->grants->setExpiry($grant->id, $expiresAt, $period, $actor->id)
                    ?? throw new GrantUnchangedException(null),
            );
        } catch (GrantUnchangedException) {
            return null;
        }
    }
}
