<?php

namespace App\Repositories;

use App\Models\TemplateVersion;
use RuntimeException;

/**
 * Raised by {@see TemplateVersionRepository} from inside its locked section when a write would change nothing: the
 * body is the active version's already, or the version is the active one already. Thrown rather than returned so it
 * rolls the surrounding audited transaction back, which is what keeps work that did not happen out of the audit log
 * (AD-16) and out of the version numbers. The Commands catch it and hand back `$version`; it never reaches a caller.
 */
final class TemplateUnchangedException extends RuntimeException
{
    public function __construct(public readonly TemplateVersion $version)
    {
        parent::__construct("Template version {$version->id} is already the active one with this body.");
    }
}
