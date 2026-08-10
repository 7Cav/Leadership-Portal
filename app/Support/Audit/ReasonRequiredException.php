<?php

namespace App\Support\Audit;

use RuntimeException;

/**
 * The action's audit policy requires a reason (AD-16: personnel actions and report sends), and
 * none was supplied. Thrown before the mutation runs — the envelope is the one place this rule
 * is enforced, so no Command can commit a reason-required action unreasoned.
 */
class ReasonRequiredException extends RuntimeException
{
    public static function forAction(string $action): self
    {
        return new self("The action \"{$action}\" requires a reason before it can be recorded.");
    }
}
