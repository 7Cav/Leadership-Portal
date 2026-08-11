<?php

namespace App\Repositories;

use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sole DB access for explicit, non-admin staff scope (AD-2, AD-7, AD-22). The creating/revoking
 * writer is Story 1.12's audited Command; this repository is currently read-only, serving the
 * ScopeResolver. Deliberately not naming that class here — Deptrac forbids
 * `Repository → Authorization` (AD-1, mirrors AD-2), and pint's `fully_qualified_strict_types`
 * fixer turns any `{@see}` reference into a real `use` import, which would quietly create that
 * reverse edge.
 */
class PortalRoleGrantRepository
{
    /**
     * Every unit a user has been explicitly granted, for the resolver to expand into subtrees.
     * A grant always names a unit (AD-8) — there is no scopeless row.
     *
     * @return Collection<int, Unit>
     */
    public function grantedUnitsFor(User $user): Collection
    {
        return PortalRoleGrant::query()
            ->where('user_id', $user->getKey())
            ->with('unit')
            ->get()
            ->pluck('unit');
    }
}
