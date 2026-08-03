<?php

namespace App\Commands\Auth;

use RuntimeException;

/**
 * Roster enrichment could not produce a profile — the API was unreachable or errored, the
 * payload was unusable, no serving roster carries this forum user id, or no Cav credential
 * is configured. Lives in the CommandHandler layer so the controller may catch it
 * (Controller → CommandHandler is allowed; Controller → Integration is not).
 *
 * This is a *degradation* signal, not a login failure: the caller signs the user in
 * unenriched. The user-facing degraded experience is Story 1.7.
 */
class CavEnrichmentException extends RuntimeException {}
