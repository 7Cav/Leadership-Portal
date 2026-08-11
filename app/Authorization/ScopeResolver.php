<?php

namespace App\Authorization;

use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

/**
 * The single authority for "which units may this user read" (AD-8) — every echelon-scoped
 * read filters through here, and no controller or repository caller hand-rolls visibility.
 *
 * Effective scope is deny-by-default: it starts empty and only ever grows, from two sources
 * that are unioned together —
 *
 *  - **Admin** (a user-profile attribute, AD-7, never a grant) short-circuits to the full tree.
 *  - **`portal_role_grants`** — explicit, audited scope for non-admin staff whose access the
 *    roster can't imply. Each held unit's subtree is expanded via its materialized `path`
 *    (AD-18), so a multi-billet / dual-hatted user's several grants union into one set.
 *
 * **Roster-derived scope is not yet computed here.** AD-7 also names a *default* command
 * subtree derived from synced Cav roster context (rank + billet + section/platoon membership),
 * but nothing in the portal yet correlates a trooper's billet to a `units` node — that
 * correlation is Epic 2's `troopers` cache (`primary_section_id`), not built. Until it lands
 * (or a follow-up story adds the mapping), a user with no grant and no admin flag resolves to
 * an empty set here — deny-by-default, not a mapping bug.
 */
class ScopeResolver
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly PortalRoleGrantRepository $grants,
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
            ->flatMap(fn ($unit) => $this->units->subtreeIds($unit))
            ->unique()
            ->values();
    }
}
