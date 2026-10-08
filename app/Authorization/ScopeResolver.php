<?php

namespace App\Authorization;

use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

/**
 * The single authority for "which units may this user read" (AD-8) — every echelon-scoped
 * read filters through here, and no controller or repository caller hand-rolls visibility.
 *
 * Effective scope is deny-by-default: it starts empty and only ever grows, from three sources —
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
 */
class ScopeResolver
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly PortalRoleGrantRepository $grants,
        private readonly UnitCommandMappingRepository $commands,
    ) {}

    /**
     * @return Collection<int, int> unit ids the user may read
     */
    public function resolve(User $user): Collection
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
}
