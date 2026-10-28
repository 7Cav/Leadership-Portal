<?php

namespace App\Authorization;

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitActingLeadRepository;
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
 *    roster can't imply. A grant counts only while its holder is a serving trooper (the same
 *    allow-list the roster-derived half uses), so a staffer who leaves the Cav loses it at once
 *    rather than when someone remembers to revoke it.
 *  - **Regimental HQ staff** (decided by the S6, 2026-10-28) — a serving trooper placed directly on
 *    the regiment node reads the whole regiment, exactly as a staff grant on the regiment would.
 *    The org import places every Regimental HQ billet there (the Chief of Staff, the Adjutants
 *    General, the Finance Officer, any billet Cav adds to that group), so general staff whose billet
 *    commands nothing are not scopeless. Read from the placement on every call, like the billet:
 *    moved elsewhere or no longer serving, it is gone.
 *
 * The roster-derived unit and every read-only unit are each expanded to their subtree via the
 * materialized `path` (AD-18) and unioned into one set, so a leader who also holds grants — or a
 * dual-hatted user with several — sees each unit once.
 *
 * Writes are narrower: {@see writeScope()} leaves the grants and the Regimental HQ read out, so a
 * staff grant reads an echelon but never marks, confirms, sends or relinks anything in it. Only
 * Admin and the roster-derived command write.
 *
 * **View-as** (AD-9, Story 1.13) then narrows that set, never widens it: the effective scope
 * {@see resolve()} hands back is `real ∩ target`, and the stored target is re-checked against the
 * user's *current* real authority on every call, so a target that has fallen outside it — a lost
 * billet, a revoked or lapsed grant, a revoked Admin flag — simply stops applying.
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
        private readonly UnitActingLeadRepository $actingLeads,
        private readonly ActingBillet $billet,
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
     * The units the user may write to: their real command authority (Admin or the roster-derived
     * subtree, never a staff grant), narrowed to their active view-as target when they have one.
     * Every write handler gates on this, not {@see resolve()}: a staff grant lets someone read an
     * echelon, not mark, confirm, send or relink anything in it.
     *
     * @return Collection<int, int> unit ids the user may write to
     */
    public function writeScope(User $user): Collection
    {
        $command = $this->commandScope($user);
        $preview = $this->activeViewAs($user);

        return $preview === null ? $command : $command->intersect($preview->scope)->values();
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

        // realScopes() must add the same read-only units: change both together.
        return $this->readOnlyUnitsWhileServing($user)
            ->merge($this->commandedUnits($user))
            ->filter()
            ->flatMap(fn ($unit) => $this->units->subtreeIds($unit))
            ->unique()
            ->values();
    }

    /**
     * {@see realScope()} for many users at once, keyed by user id: the same three sources and the same rules, read in a
     * fixed number of queries however many users are asked about (#496). The tree is read once and every subtree is
     * worked out from it, where {@see realScope()} asks the database for each. What the act as picker reads, which
     * would otherwise run a batch of queries per person below a leader.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, Collection<int, int>> unit ids by user id
     */
    public function realScopes(Collection $users): Collection
    {
        if ($users->isEmpty()) {
            return collect();
        }

        $tree = $this->units->tree();
        // By the unit's own path, as subtreeIds() matches it; worked out once per path, as many people share a unit.
        $subtrees = [];
        $subtree = function (Unit $unit) use ($tree, &$subtrees) {
            return $subtrees[$unit->path] ??= $tree->filter(fn (Unit $node) => str_starts_with($node->path, $unit->path))->pluck('id');
        };

        // Only a user linked to a milpac who is not an Admin has a billet, acting leads or grants to read.
        $linked = $users->filter(fn (User $user) => ! $user->is_admin && $user->cav_id !== null);
        $cavIds = $linked->map(fn (User $user) => (int) $user->cav_id)->unique()->values()->all();
        $troopers = $this->troopers->byCavId($cavIds);
        $billets = $cavIds === [] ? collect() : $this->commands->commandedUnitsByCavId($cavIds);
        $acting = $this->actingLeads->unitsLedByTroopers($troopers->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
        // A grant, and the regiment for Regimental HQ staff, count only while the holder serves
        // (readOnlyUnitsWhileServing()), so only theirs are read.
        $serving = $linked
            ->filter(fn (User $user) => $troopers->get((int) $user->cav_id)?->status?->isServing() ?? false)
            ->map(fn (User $user) => (int) $user->id)
            ->values();
        $grants = $this->grants->grantedUnitIdsByUserId($serving->all());
        $servingIds = $serving->flip();
        $byId = $tree->keyBy('id');

        return $users->mapWithKeys(function (User $user) use ($tree, $subtree, $troopers, $billets, $acting, $servingIds, $grants, $byId) {
            if ($user->is_admin) {
                return [$user->id => $tree->pluck('id')->values()];
            }

            $trooper = $user->cav_id === null ? null : $troopers->get((int) $user->cav_id);
            // As readOnlyUnitsWhileServing(): while serving, their grants and, for Regimental HQ staff placed on the
            // regiment itself, the regiment. realScope() must add the same: change both together.
            $placed = $trooper?->primary_section_id === null ? null : $byId->get((int) $trooper->primary_section_id);
            $granted = $servingIds->has($user->id)
                ? $grants->get($user->id, collect())->map(fn (int $id) => $byId->get($id))
                    ->push(self::isRegimentalHqPlacement($placed) ? $placed : null)
                : collect();
            // As commandedUnits(): a billet held on this request is the only command, else the synced billet and
            // the acting leads, none for a user with no milpac link.
            $held = $this->billet->of($user);
            $commanded = match (true) {
                $held !== null => collect([$held]),
                $user->cav_id === null => collect(),
                default => collect([$billets->get((int) $user->cav_id)])
                    ->merge($trooper === null ? collect() : $acting->get((int) $trooper->id, collect())),
            };

            return [$user->id => $granted
                ->merge($commanded)
                ->filter()
                ->flatMap($subtree)
                ->unique()
                ->values()];
        });
    }

    /**
     * Real command authority, ignoring view-as and grants: the full tree for an Admin, otherwise
     * the subtree of the unit the user's synced billet commands (empty when it commands none).
     *
     * @return Collection<int, int> unit ids
     */
    private function commandScope(User $user): Collection
    {
        if ($user->is_admin) {
            return $this->units->allIds();
        }

        return $this->commandedUnits($user)
            ->flatMap(fn (Unit $unit) => $this->units->subtreeIds($unit))
            ->unique()
            ->values();
    }

    /**
     * The units the user commands: the one their synced billet commands, plus every unit an Admin made them acting
     * lead of (decided 2026-10-25), each only while their trooper serves. An acting lead is exactly a Platoon
     * Leader's command over that unit: read, write and the unit sync.
     *
     * @return Collection<int, Unit>
     */
    private function commandedUnits(User $user): Collection
    {
        // An Admin acting as a billet (#379 follow-up) commands that unit and nothing else: their stand-in has no milpac
        // link, so no billet or acting lead of their own is found below.
        $held = $this->billet->of($user);
        if ($held !== null) {
            return collect([$held]);
        }

        return collect([$this->commands->commandedUnitFor($user)])
            ->filter()
            ->merge($this->actingLeads->unitsLedBy($user))
            ->values();
    }

    /**
     * The units the user commands, by synced billet or as an acting lead, never by staff grant or Admin flag: the one
     * thing a leader's authority over people below them (act as, #432) is measured from.
     *
     * @return Collection<int, Unit>
     */
    public function commandedBy(User $user): Collection
    {
        return $this->commandedUnits($user);
    }

    /**
     * The unit ids strictly below the given commanded units: their subtrees without the commanded units themselves,
     * so nobody who leads one of them (the leader's own billet, a peer on it) is below. Empty for no units.
     *
     * @param  Collection<int, Unit>  $commanded
     * @return Collection<int, int>
     */
    public function strictlyBelow(Collection $commanded): Collection
    {
        return $commanded
            ->flatMap(fn (Unit $unit) => $this->units->subtreeIds($unit))
            ->unique()
            ->diff($commanded->pluck('id'))
            ->values();
    }

    /**
     * Whether the user may refresh this unit's troopers from Cav: an Admin for any unit, otherwise the leader whose
     * synced command billet covers it (the unit they command or anything beneath it). Deliberately narrower than
     * {@see realScope()}: a staff grant lets someone read an echelon, not trigger writes to it. Real authority, not
     * the view-as preview: writes never follow a preview (AD-9).
     */
    public function commandsUnit(User $user, Unit $unit): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $this->commandedUnits($user)->contains(fn (Unit $commanded) => $this->units->subtreeIds($commanded)->contains($unit->id));
    }

    /**
     * The units the user commands (real authority: billet, acting leads or a held billet, never a grant or Admin) whose
     * subtree holds `$unitId`, lowest echelon first. The counseling rules (#381) ask at which echelon a leader reaches a
     * trooper, not just whether they do, so they read the commands themselves rather than {@see commandsUnit()}.
     *
     * @return Collection<int, Unit>
     */
    public function commandingUnitsOver(User $user, int $unitId): Collection
    {
        return $this->commandedUnits($user)
            ->filter(fn (Unit $commanded) => $this->units->subtreeIds($commanded)->contains($unitId))
            ->unique(fn (Unit $commanded) => $commanded->id)
            ->sortByDesc(fn (Unit $commanded) => [$commanded->type->rank(), $commanded->id])
            ->values();
    }

    /**
     * The units the user reads without commanding them, only while they are a serving trooper: `users.cav_id` linked to
     * a cached trooper whose standing is on {@see TrooperStatus::serving()}. Two sources, neither ever in
     * {@see writeScope()}:
     *
     *  - their staff grants. A grant is staff scope for a *member* of the unit: once the holder leaves (the roster sync
     *    flips them `inactive`), or was never linked to a milpac at all, every grant they hold stops counting on the next
     *    read, with no one having to remember to revoke it. The rows themselves are kept, so the staff grants page can
     *    show them as lapsed and an Admin can revoke them on the record; a holder who returns to a serving roster gets
     *    them back.
     *  - the regiment, for Regimental HQ staff: a trooper placed directly on the regiment node, where the org import
     *    places every Regimental HQ billet (`CommandBillet::headquartersOf()`). Holding groups and staff departments are
     *    their own units under the regiment, so their members never match.
     *
     * Admins never reach here. The bulk realScopes() (#498) must apply the same two sources.
     *
     * @return Collection<int, Unit>
     */
    private function readOnlyUnitsWhileServing(User $user): Collection
    {
        $trooper = $user->cav_id === null ? null : $this->troopers->byCavId([(int) $user->cav_id])->get((int) $user->cav_id);
        if (! ($trooper?->status?->isServing() ?? false)) {
            return collect();
        }

        return $this->grants->grantedUnitsFor($user)
            ->push($this->regimentPlacing($trooper))
            ->filter()
            ->values();
    }

    /** The regiment when the trooper is placed directly on it (Regimental HQ staff), else null. */
    private function regimentPlacing(Trooper $trooper): ?Unit
    {
        $placed = $trooper->primary_section_id === null ? null : $this->units->find($trooper->primary_section_id);

        return self::isRegimentalHqPlacement($placed) ? $placed : null;
    }

    /**
     * Who reads the whole regiment as Regimental HQ staff right now: the serving troopers placed directly on the regiment
     * node, by username. The same two tests {@see readOnlyUnitsWhileServing()} and {@see realScopes()} apply one trooper
     * at a time ({@see TrooperStatus::serving()} and {@see isRegimentalHqPlacement()}), so the Staff grants page lists
     * exactly the people this class gives the regiment to. A trooper with no portal account yet is listed too: they read
     * it from their first sign-in. Two queries.
     *
     * @return Collection<int, Trooper>
     */
    public function regimentalHqStaff(): Collection
    {
        $regimentIds = $this->units->tree()
            ->filter(fn (Unit $unit) => self::isRegimentalHqPlacement($unit))
            ->pluck('id');

        return $this->troopers->servingPlacedIn($regimentIds);
    }

    /** Whether a trooper placed on `$unit` is Regimental HQ staff: placed on the regiment node itself, nothing below it. */
    private static function isRegimentalHqPlacement(?Unit $unit): bool
    {
        return $unit?->type === UnitType::Regiment;
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
     * Whether the user holds a leadership billet as a read sees it: they command at least one unit (a synced billet,
     * an acting lead, or Admin), never through a staff grant alone. A preview answers for whoever it shows: a unit
     * preview is that unit's leader, a person preview is that person's own command. Not {@see writeScope()}: a person
     * preview narrows the Admin's tree to the person's read scope, grants included, so a grant holder would pass.
     */
    public function leads(User $user): bool
    {
        $preview = $this->activeViewAs($user);
        if ($preview === null) {
            return $this->commandScope($user)->isNotEmpty();
        }

        if ($preview->target->kind === ViewAsKind::Unit) {
            return $preview->scope->isNotEmpty();
        }

        $previewed = $this->users->find($preview->target->id);

        return $previewed !== null && $this->commandScope($previewed)->isNotEmpty();
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
     *    user's own real scope (which an Admin's full tree always contains);
     *  - a **trooper** with no portal account, by an Admin only, while they serve; the preview is
     *    what their billet and acting leads would give them ({@see rosterAccount()}).
     *
     * Either way the result is intersected with the viewer's real scope, here and only here.
     */
    public function evaluate(User $viewer, ViewAsTarget $target): ?ActiveViewAs
    {
        $real = $this->realScope($viewer);

        $preview = match ($target->kind) {
            ViewAsKind::Unit => $this->asUnit($real, $target),
            ViewAsKind::User => $viewer->is_admin ? $this->asUser($viewer, $target) : null,
            ViewAsKind::Trooper => $viewer->is_admin ? $this->asTrooper($target) : null,
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

    /**
     * A serving trooper who has never signed in, as the account they would have: unsaved, never an Admin, with no grants,
     * carrying only their milpac link, so their billet and acting leads give exactly the scope they would sign in with.
     * Null when the trooper does not exist, has left, or already has an account (preview that account instead).
     */
    public function rosterAccount(int $trooperId): ?User
    {
        $trooper = $this->troopers->find($trooperId);
        if ($trooper === null || ! $trooper->status?->isServing() || $this->users->withCavId((int) $trooper->cav_id) !== null) {
            return null;
        }

        $account = new User;
        $account->forceFill(['username' => $trooper->username, 'cav_id' => $trooper->cav_id, 'is_admin' => false]);

        return $account;
    }

    /** The unit a roster account would lead: their billet's, else the first they are acting lead of; null for none. */
    public function ledBy(User $account): ?Unit
    {
        return $this->commandedUnits($account)->first();
    }

    /**
     * Whose billet and acting leads decide where a page opens when no unit is named (the roster's default unit, the
     * rollcall's landing section, #426): the user's own without a preview, the previewed person's in a person preview
     * (their account, or the roster account of a trooper who has none), so the preview opens where that person would.
     * Null in a unit preview and for a person who no longer exists: the page then opens at the top of the scope, which a
     * unit preview is that unit.
     */
    public function landingAccount(User $user): ?User
    {
        $preview = $this->activeViewAs($user);

        return match ($preview?->target->kind) {
            null => $user,
            ViewAsKind::Unit => null,
            ViewAsKind::User => $this->users->find($preview->target->id),
            ViewAsKind::Trooper => $this->rosterAccount($preview->target->id),
        };
    }

    /**
     * The highest units of the user's effective scope (view as and act as included): those whose parent is outside
     * it, in tree order. Scope is subtree-closed, so every other unit in it sits under one of these. What the shell
     * names as the viewer's scope (#426); empty for a user with no scope.
     *
     * @return Collection<int, Unit>
     */
    public function topUnits(User $user): Collection
    {
        $scope = $this->resolve($user)->flip();

        return $this->units->tree()
            ->filter(fn (Unit $unit) => $scope->has($unit->id) && ($unit->parent_id === null || ! $scope->has($unit->parent_id)))
            ->values();
    }

    private function asTrooper(ViewAsTarget $target): ?ActiveViewAs
    {
        $account = $this->rosterAccount($target->id);
        if ($account === null) {
            return null;
        }

        $unit = $this->ledBy($account);
        $trooper = $this->troopers->find($target->id);

        return new ActiveViewAs(
            target: $target,
            scope: $this->realScope($account),
            label: $trooper->display_name,
            designation: $unit ? ($unit->callsign ?? $unit->name) : null,
            unitName: $unit?->name,
            admin: false,
        );
    }
}
