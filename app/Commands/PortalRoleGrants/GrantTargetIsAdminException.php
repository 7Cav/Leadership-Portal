<?php

namespace App\Commands\PortalRoleGrants;

use App\Models\User;
use RuntimeException;

/**
 * A grant can only attach non-admin staff scope (AD-7) — an Admin already resolves the full
 * tree, so granting one a node would be meaningless and risks being read as the admin flag
 * itself being settable through this UI, which it never is.
 */
class GrantTargetIsAdminException extends RuntimeException
{
    public static function forUser(User $user): self
    {
        return new self("{$user->username} is already an Admin and cannot receive a staff grant.");
    }
}
