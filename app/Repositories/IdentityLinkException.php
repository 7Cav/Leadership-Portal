<?php

namespace App\Repositories;

use RuntimeException;
use Throwable;

/**
 * A portal-owned identity link could not be written.
 *
 * Exists for the same reason `Integration/` has its own exception type: the DB layer is
 * reachable only from `Repositories/` (AD-2), and Deptrac enforces that a CommandHandler cannot
 * name `Illuminate\Database\QueryException`. So a write failure is translated here, at the layer
 * that owns the database, into something the caller is allowed to catch.
 *
 * The caller that matters is roster enrichment, which must degrade a session rather than break a
 * login: `users.cav_id` is unique, so two concurrent logins resolving the same milpac id can
 * collide even with the write wrapped in a transaction.
 */
class IdentityLinkException extends RuntimeException
{
    public static function cavIdNotLinked(int $userId, int $cavId, Throwable $previous): self
    {
        return new self(
            "Could not link cav id {$cavId} to user {$userId}.",
            previous: $previous,
        );
    }
}
