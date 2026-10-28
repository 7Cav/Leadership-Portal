<?php

namespace App\Docs;

use App\Authorization\ScopeResolver;
use App\Models\User;

/**
 * The audience a viewer reads the guides as, from their real authority through the ScopeResolver (AD-8), so there is
 * one notion of "Admin" and "leader" in the portal. A staff grant is read-only and does not make someone a leader. A
 * view-as preview (AD-9) answers for the person or unit it shows, as every other screen does.
 */
final class ViewerAudience
{
    public function __construct(private readonly ScopeResolver $scope) {}

    public function of(User $viewer): Audience
    {
        return match (true) {
            $this->scope->actsAsAdmin($viewer) => Audience::Admin,
            $this->scope->leads($viewer) => Audience::Leader,
            default => Audience::Everyone,
        };
    }
}
