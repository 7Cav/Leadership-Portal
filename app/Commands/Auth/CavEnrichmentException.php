<?php

namespace App\Commands\Auth;

use App\Integration\Cav\CavFailureReason;
use RuntimeException;
use Throwable;

/**
 * Roster enrichment could not produce a profile. Lives in the CommandHandler layer so the
 * controller may catch it (Controller → CommandHandler is allowed; Controller → Integration is
 * not).
 *
 * This is a *degradation* signal, not a login failure: the caller signs the user in unenriched.
 * The user-facing degraded experience is Story 1.7.
 *
 * The `reason` is carried through rather than flattened, because the causes are not equally
 * interesting — a trooper who holds no milpac is normal, a roster outage is not — and the
 * caller needs to distinguish them to decide how loudly to record the failure.
 */
class CavEnrichmentException extends RuntimeException
{
    public function __construct(
        public readonly CavFailureReason $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
