<?php

namespace App\Repositories;

use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Sole DB access for explicit, non-admin staff scope (AD-2, AD-7, AD-22). Deliberately not
 * naming `Authorization\ScopeResolver` here — Deptrac forbids `Repository → Authorization`
 * (AD-1, mirrors AD-2), and pint's `fully_qualified_strict_types` fixer turns any `{@see}`
 * reference into a real `use` import, which would quietly create that reverse edge.
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

    /**
     * Every grant, for the Admin grants listing (Story 1.12) — eager-loaded so the controller
     * never lazy-loads `user`/`unit` off a row (AD-2's `preventLazyLoading()` outside
     * production).
     *
     * @return EloquentCollection<int, PortalRoleGrant>
     */
    public function all(): EloquentCollection
    {
        return PortalRoleGrant::query()->with(['user', 'unit'])->orderBy('id')->get();
    }

    /**
     * The grant attaching `$user` to `$unit`, if one exists.
     */
    public function find(User $user, Unit $unit): ?PortalRoleGrant
    {
        return PortalRoleGrant::query()
            ->where('user_id', $user->getKey())
            ->where('unit_id', $unit->getKey())
            ->first();
    }

    /**
     * Attach `$user` to `$unit` (Story 1.12). Idempotent on the unique `[user_id, unit_id]`
     * constraint — re-granting an already-held node returns the existing row rather than
     * throwing or duplicating it.
     */
    public function grant(User $user, Unit $unit): PortalRoleGrant
    {
        return PortalRoleGrant::query()->firstOrCreate([
            'user_id' => $user->getKey(),
            'unit_id' => $unit->getKey(),
        ]);
    }

    /**
     * Detach `$user` from `$unit`, returning the removed row for the audit envelope (its
     * attributes remain readable in memory after `delete()`), or `null` if no such grant
     * existed.
     */
    public function revoke(User $user, Unit $unit): ?PortalRoleGrant
    {
        $grant = $this->find($user, $unit);

        if ($grant === null) {
            return null;
        }

        $grant->delete();

        return $grant;
    }
}
