<?php

namespace App\Authorization;

use App\Models\Trooper;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Collection;

/**
 * The single authority for "which units may this user read" (AD-8) — every echelon-scoped
 * read filters through here, and no controller or repository caller hand-rolls visibility.
 *
 * Real authority is deny-by-default: it starts empty and only ever grows, from three sources —
 *
 *  - **Admin** (a user-profile attribute, AD-7, never a grant) short-circuits to the full tree.
 *  - **Roster-derived** (AD-7, Story 1.16) — the unit the user's synced primary Cav billet
 *    commands (`troopers.cav_position_id` → `unit_command_mappings`, written by the org import):
 *    a Section Leader gets their section, a Platoon Leader their platoon, a Commander their
 *    company, battalion and regimental command their battalion / the regiment. Read fresh on
 *    every call, so a roster sync that moves the trooper to another billet moves their scope with
 *    it. A departed trooper, an unmapped billet or a user with no synced trooper derive nothing.
 *  - **`portal_role_grants`** — explicit, audited scope for non-admin staff whose access the
 *    roster can't imply.
 *
 * The roster-derived unit and every granted unit are each expanded to their subtree via the
 * materialized `path` (AD-18) and unioned into one set, so a leader who also holds grants — or a
 * dual-hatted user with several — sees each unit once.
 *
 * **View-as** (AD-9, Story 1.13) then narrows that set, never widens it: the effective scope
 * {@see resolve()} hands back is `real ∩ target`, and the stored target is re-checked against the
 * user's *current* real authority on every call, so a target that has fallen outside it — a lost
 * billet, a revoked grant, a revoked Admin flag — simply stops applying.
 */
class ScopeResolver
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly PortalRoleGrantRepository $grants,
        private readonly UnitCommandMappingRepository $commands,
        private readonly UserRepository $users,
        private readonly TrooperRepository $troopers,
        private readonly ViewAs $viewAs,
    ) {}

    /**
     * The effective scope every read filters through: the user's real authority, narrowed to
     * their active view-as target when they have one.
     *
     * @return Collection<int, int> unit ids the user may read
     */
    public function resolve(User $user): Collection
    {
        return $this->activeViewAs($user)?->scope ?? $this->realScope($user);
    }

    /**
     * The real-authority ceiling, ignoring view-as.
     *
     * @return Collection<int, int> unit ids
     */
    public function realScope(User $user): Collection
    {
        if ($user->is_admin) {
            return $this->units->allIds();
        }

        return $this->grants->grantedUnitsFor($user)
            ->push($this->commands->commandedUnitFor($user))
            ->filter()
            ->flatMap(fn ($unit) => $this->units->subtreeIds($unit))
            ->unique()
            ->values();
    }

    /**
     * The viewer's stored view-as target, if it still holds against their real authority right
     * now. Null when there is no target or it no longer passes {@see evaluate()} — and a target
     * that no longer passes is dropped from the session there and then, so a later return of the
     * same authority can never silently switch a lapsed preview back on.
     */
    public function activeViewAs(User $viewer): ?ActiveViewAs
    {
        $target = $this->viewAs->targetFor($viewer);
        if ($target === null) {
            return null;
        }

        $active = $this->evaluate($viewer, $target);
        if ($active === null) {
            $this->viewAs->exit();
        }

        return $active;
    }

    /**
     * Whether the user should be treated as an Admin on a read (Story 1.13): their real flag,
     * unless a view-as preview of a non-Admin is active — then the preview shows what that leader
     * sees, Admin-only screens included. Narrows only: a non-Admin never becomes one here. Writes
     * keep checking the real flag; a preview refuses them anyway.
     */
    public function actsAsAdmin(User $user): bool
    {
        return $user->is_admin && ($this->activeViewAs($user)?->admin ?? true);
    }

    /**
     * Whether the user may see this trooper (AD-8, one gate for every screen that shows one): a trooper placed
     * in a section is visible when that section is in the user's effective scope, which a view-as preview
     * narrows (AD-9). A trooper the sync has not placed anywhere belongs to no scope, so only an Admin (as the
     * view-as preview allows) sees them, the same people who can place them. Callers answer 404 when this is
     * false, so a viewer cannot tell an out-of-scope trooper from one that does not exist.
     */
    public function canSee(User $user, Trooper $trooper): bool
    {
        return $trooper->primary_section_id === null
            ? $this->actsAsAdmin($user)
            : $this->resolve($user)->contains($trooper->primary_section_id);
    }

    /**
     * Whether `$viewer` may view as `$target`, and what that leaves them seeing. Null means
     * refused — the caller falls back to real scope, it never widens (AD-9):
     *
     *  - a **unit** must exist and sit inside the viewer's real scope; the preview is its subtree;
     *  - a **user** may be previewed by an Admin only, never by themselves; the preview is that
     *    user's own real scope (which an Admin's full tree always contains).
     *
     * Either way the result is intersected with the viewer's real scope, here and only here.
     */
    public function evaluate(User $viewer, ViewAsTarget $target): ?ActiveViewAs
    {
        $real = $this->realScope($viewer);

        $preview = match ($target->kind) {
            ViewAsKind::Unit => $this->asUnit($real, $target),
            ViewAsKind::User => $viewer->is_admin ? $this->asUser($viewer, $target) : null,
        };

        // The one place every kind passes through: whatever a target would see is cut down to
        // the viewer's own real scope, so no kind — present or future — can widen it.
        return $preview?->narrowedTo($real);
    }

    /**
     * @param  Collection<int, int>  $real
     */
    private function asUnit(Collection $real, ViewAsTarget $target): ?ActiveViewAs
    {
        $unit = $this->units->find($target->id);

        if ($unit === null || ! $real->contains($unit->id)) {
            return null;
        }

        $designation = $unit->callsign ?? $unit->name;

        return new ActiveViewAs(
            target: $target,
            scope: $this->units->subtreeIds($unit),
            label: $designation,
            designation: $designation,
            unitName: $unit->name,
            admin: false,
        );
    }

    private function asUser(User $viewer, ViewAsTarget $target): ?ActiveViewAs
    {
        $user = $this->users->find($target->id);

        if ($user === null || $user->id === $viewer->id) {
            return null;
        }

        $unit = $this->commands->commandedUnitFor($user);
        $rank = $user->cav_id !== null ? $this->troopers->ranksByCavId([$user->cav_id])->get($user->cav_id) : null;

        return new ActiveViewAs(
            target: $target,
            scope: $this->realScope($user),
            label: trim("{$rank} {$user->username}"),
            designation: $unit ? ($unit->callsign ?? $unit->name) : null,
            unitName: $unit?->name,
            admin: $user->is_admin,
        );
    }
}
