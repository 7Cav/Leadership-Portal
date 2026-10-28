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
interface PortalRoleGrantRepository
{
    /**
     * Every unit a user has been explicitly granted, for the resolver to expand into subtrees.
     * A grant always names a unit (AD-8) — there is no scopeless row.
     *
     * @return Collection<int, Unit>
     */
    public function grantedUnitsFor(User $user): Collection;

    /**
     * {@see grantedUnitsFor()} for many users at once, as unit ids grouped by user id: one query, for a list that
     * works out many people's scope together (the act as picker, #496). A user with no grant is absent.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, Collection<int, int>>
     */
    public function grantedUnitIdsByUserId(array $userIds): Collection;

    /**
     * Every grant, for the Admin grants listing (Story 1.12) — eager-loaded so the controller
     * never lazy-loads `user`/`unit` off a row (AD-2's `preventLazyLoading()` outside
     * production).
     *
     * @return EloquentCollection<int, PortalRoleGrant>
     */
    public function all(): EloquentCollection;

    /**
     * The grant attaching `$user` to `$unit`, if one exists.
     */
    public function find(User $user, Unit $unit): ?PortalRoleGrant;

    /**
     * The grant with this id, if it exists: the revoke route names a grant by id, and resolves it
     * only after its Admin gate.
     */
    public function findById(int $id): ?PortalRoleGrant;

    /**
     * Attach `$user` to `$unit` (Story 1.12). Idempotent on the unique `[user_id, unit_id]`
     * constraint — re-granting an already-held node returns the existing row rather than
     * throwing or duplicating it, including when a racing grant committed it inside the caller's
     * transaction. The returned row's `wasRecentlyCreated` says whether this call wrote it.
     */
    public function grant(User $user, Unit $unit): PortalRoleGrant;

    /**
     * Detach `$user` from `$unit`, returning the removed row for the audit envelope (its
     * attributes remain readable in memory after `delete()`), or `null` if no such grant
     * existed. The row is read with a lock, so a racing revoke that committed first yields `null`.
     */
    public function revoke(User $user, Unit $unit): ?PortalRoleGrant;

    /**
     * Whether anyone holds a grant on `$unit` — `portal_role_grants.unit_id` restricts deletes,
     * so `DeleteUnit` checks this first to refuse with a reason instead of a raw constraint error.
     */
    public function hasGrantsOn(Unit $unit): bool;
}
