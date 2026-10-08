<?php

namespace App\Authorization;

/**
 * The node or person a viewer has asked to see the portal as. Only a *request*: it carries no
 * authority of its own and is re-validated against the viewer's real scope on every read
 * ({@see ScopeResolver::evaluate()}, AD-9).
 */
final readonly class ViewAsTarget
{
    public function __construct(
        public ViewAsKind $kind,
        public int $id,
    ) {}
}
