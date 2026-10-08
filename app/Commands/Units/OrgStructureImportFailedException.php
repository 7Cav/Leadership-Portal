<?php

namespace App\Commands\Units;

use RuntimeException;
use Throwable;

/**
 * The org-structure import could not run (story 2.9) — the Cav API was unreachable, answered
 * with an error or an unusable payload, no `CAV_API_KEY` is configured, or Cav published nothing
 * that looks like a line company. Lives in the
 * CommandHandler layer so the controller can catch it (Controller → Integration is forbidden).
 * Thrown before any write, so a failed import never leaves a partial tree behind.
 */
class OrgStructureImportFailedException extends RuntimeException
{
    public static function cavUnavailable(Throwable $previous): self
    {
        return new self('Could not read the Cav position groups: '.$previous->getMessage(), previous: $previous);
    }

    public static function noLineCompanies(): self
    {
        return new self('The Cav position groups listed no line companies, so there was nothing to import.');
    }
}
