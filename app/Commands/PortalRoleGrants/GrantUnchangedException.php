<?php

namespace App\Commands\PortalRoleGrants;

use App\Models\PortalRoleGrant;
use RuntimeException;

/**
 * Raised inside the audited transaction when a grant or revoke turns out, under the repository's
 * lock, to change nothing: a racing request already granted the node, or already revoked it. Thrown
 * rather than returned so it rolls the transaction back, which keeps work that did not happen out of
 * the audit log (AD-16). {@see GrantStaffScope} and {@see RevokeStaffScope} catch it and answer as
 * their own no-op does; it never reaches a caller.
 */
final class GrantUnchangedException extends RuntimeException
{
    public function __construct(public readonly ?PortalRoleGrant $grant)
    {
        parent::__construct('Nothing changed: the staff grant was already set that way. Reload the page to see it.');
    }
}
