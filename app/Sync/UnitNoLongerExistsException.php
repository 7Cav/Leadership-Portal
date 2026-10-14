<?php

namespace App\Sync;

use RuntimeException;

/**
 * An echelon sync was queued for a unit that has since been deleted. Permanent, so the job fails at once instead of
 * retrying, and no `sync_runs` row is opened for it.
 */
class UnitNoLongerExistsException extends RuntimeException
{
    public static function withId(int $unitId): self
    {
        return new self("Unit {$unitId} no longer exists; there is nothing to sync.");
    }
}
