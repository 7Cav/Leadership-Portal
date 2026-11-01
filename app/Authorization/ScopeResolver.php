<?php

namespace App\Authorization;

use App\Models\AdminBillet;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AdminBilletRepository;
use App\Repositories\PortalRoleGrantRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitOversightRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Collection;

/**
 * The single authority for "which units may this user read" (AD-8) — every echelon-scoped
 * read filters through here, and no controller or repository caller hand-rolls visibility.
 *
 * Real authority is deny-by-default: it starts empty and only ever grows, from three sources —
 *
 *  - **Admin** short-circuits to the full tree: the `users.is_admin` flag (AD-7, never a grant), or an approved
 *    Admin billet (Regimental HQ, or S6 1IC and S6 2IC) ({@see isAdmin()}). Every "is this user an Admin" question in
 *    the portal is asked of {@see isAdmin()}, never of the flag alone.
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
 *  - **Regimental HQ staff** — a serving trooper placed directly on
 *    the regiment node, whose position is still a Regimental HQ billet, reads the whole regiment, exactly
 *    as a staff grant on the regiment would.
 *    The org import places every Regimental HQ billet there (the Chief of Staff, the Adjutants
 *    General, the Finance Officer, any billet Cav adds to that group), so general staff whose billet
 *    commands nothing are not scopeless. Read from the placement and the position on every call:
 *    moved elsewhere, on a billet that is not in Regimental HQ, or no longer serving, it is gone.
 *  - **Oversight** — a serving trooper an Admin named as a unit's
 *    overseer (`unit_oversights`: an Adjutant General over a battalion, the RDC Commander over ACD,
 *    DEVCOM and the Reservists) reads that unit's subtree, exactly as a staff grant on it would.
 *
 * The roster-derived unit and every read-only unit are each expanded to their subtree via the
 * materialized `path` (AD-18) and unioned into one set, so a leader who also holds grants — or a
 * dual-hatted user with several — sees each unit once.
 *
 * Writes are narrower: {@see writeScope()} leaves the grants, oversight and the Regimental HQ read out, so a
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
        private readonly AdminBilletRepository $adminBillets,
        private readonly UnitOversightRepository $oversights,
        private readonly RealActor $real,
    ) {}

    /**
     * Whether the user is an Admin right now, the one definition every Admin check reads:
     *
     *  - the `users.is_admin` flag, a person's own, set and cleared only by the audited flag Command; or
     *  - **Admin by billet**: a serving trooper whose Cav position an Admin approved in `admin_billets`, while the
     *    billet's title and group are still the ones that were confirmed, that group is still one of
     *    `portal.admin_billet_groups` (Regimental HQ) or that title one of `portal.admin_billet_titles` (S6 1IC, S6 2IC), and the trooper's own synced title is that title
     *    ({@see AdminBillet::confirmsHolder()}). Where the trooper is placed does not matter: the S6 1IC and 2IC sit in the S6
     *    department, not on the regiment. It belongs to the billet, not the person: out of the billet, no longer
     *    serving, or the billet renamed or moved (back to pending), and it is gone on the next request.
     *
     * Read fresh on every call, never cached past it and never written to `users.is_admin`. A copy the request runs as
     * or previews ({@see User::$notAdminByBillet}: an Admin acting as this person, a roster account) is an Admin by its
     * flag attribute only, so acting as or previewing a billet Admin never confers Admin, as for a flag Admin.
     */
    public function isAdmin(User $user): bool
    {
        return (bool) $user->is_admin || $this->isAdminByBillet($user);
    }

    /** The billet half of {@see isAdmin()}: false for a flag Admin who holds no approved billet. At most two queries. */
    public function isAdminByBillet(User $user): bool
    {
        if ($user->cav_id === null || $user->notAdminByBillet) {
            return false;
        }

        $trooper = $this->troopers->byCavId([(int) $user->cav_id])->get((int) $user->cav_id);

        return $trooper !== null && $this->billetAdminCavIds(collect([$trooper]))->isNotEmpty();
    }

    /**
     * {@see isAdmin()} for many users at once: the ids of the Admins among them, by flag or by billet. One query for the
     * troopers, a second only when one of them serves on a billet; none when every user is a flag Admin or has no
     * milpac link. For lists that must treat a billet Admin as an Admin (the staff grants user picker).
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, int> user ids
     */
    public function adminIdsAmong(Collection $users): Collection
    {
        $flagged = $users->filter(fn (User $user) => (bool) $user->is_admin);
        $linked = $users->filter(fn (User $user) => ! $user->is_admin && ! $user->notAdminByBillet && $user->cav_id !== null);
        if ($linked->isEmpty()) {
            return $flagged->map(fn (User $user) => (int) $user->id)->values();
        }

        $troopers = $this->troopers->byCavId($linked->map(fn (User $user) => (int) $user->cav_id)->unique()->values()->all());
        $byBillet = $this->billetAdminCavIds($troopers->values())->flip();

        return $flagged
            ->merge($linked->filter(fn (User $user) => $byBillet->has((int) $user->cav_id)))
            ->map(fn (User $user) => (int) $user->id)
            ->unique()
            ->values();
    }

    /**
     * The cav ids of the troopers among `$troopers` who are Admins by billet: serving, and holding a position whose
     * `admin_billets` row confirms them ({@see AdminBillet::confirmsHolder()}), wherever they are placed. One query for
     * those rows, none when nobody serves on a billet. The one place the billet rule is applied.
     *
     * @param  Collection<int, Trooper>  $troopers
     * @return Collection<int, int>
     */
    private function billetAdminCavIds(Collection $troopers): Collection
    {
        $holders = $troopers->filter(fn (Trooper $trooper) => ($trooper->status?->isServing() ?? false)
            && $trooper->cav_id !== null
            && $trooper->cav_position_id !== null);
        if ($holders->isEmpty()) {
            return collect();
        }

        $rows = $this->adminBillets->forPositions($holders->map(fn (Trooper $trooper) => (int) $trooper->cav_position_id)->unique()->values()->all());

        return $holders
            ->filter(fn (Trooper $trooper) => $rows->get((int) $trooper->cav_position_id)?->confirmsHolder($trooper->billet) ?? false)
            ->map(fn (Trooper $trooper) => (int) $trooper->cav_id)
            ->values();
    }

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
        if ($this->isAdmin($user)) {
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

        // Only a user linked to a milpac who is not a flag Admin has a billet, acting leads or grants to read, or an
        // Admin billet to hold.
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
        // Only serving troopers' oversight is read, so it needs no serving filter of its own below.
        $overseen = $this->oversights->unitsOverseenByTroopers($troopers->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
        $servingIds = $serving->flip();
        $byId = $tree->keyBy('id');
        // As isAdmin(): the flag, or an approved Admin billet, read from the troopers already in hand.
        $billetAdmins = $this->billetAdminCavIds($troopers->values())->flip();
        // As regimentPlacing(): the regiment only for someone placed on it who still holds a Regimental HQ billet.
        $onRegiment = $troopers->filter(fn (Trooper $trooper) => self::isRegimentalHqPlacement($byId->get((int) $trooper->primary_section_id)));
        $rhqPositions = $this->regimentalHqPositions($onRegiment);

        return $users->mapWithKeys(function (User $user) use ($tree, $subtree, $troopers, $billets, $acting, $overseen, $servingIds, $grants, $byId, $billetAdmins, $rhqPositions) {
            if ($user->is_admin || (! $user->notAdminByBillet && $user->cav_id !== null && $billetAdmins->has((int) $user->cav_id))) {
                return [$user->id => $tree->pluck('id')->values()];
            }

            $trooper = $user->cav_id === null ? null : $troopers->get((int) $user->cav_id);
            // As readOnlyUnitsWhileServing(): while serving, their grants, the units they oversee and, for Regimental HQ
            // staff placed on the regiment itself who still hold a Regimental HQ billet, the regiment. realScope() must
            // add the same: change both together.
            $placed = $trooper?->primary_section_id === null ? null : $byId->get((int) $trooper->primary_section_id);
            $rhq = self::isRegimentalHqPlacement($placed) && $rhqPositions->has((int) $trooper->cav_position_id);
            $granted = $servingIds->has($user->id)
                ? $grants->get($user->id, collect())->map(fn (int $id) => $byId->get($id))
                    ->merge($overseen->get((int) $trooper->id, collect())->map(fn (Unit $unit) => $byId->get($unit->id)))
                    ->push($rhq ? $placed : null)
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
        if ($this->isAdmin($user)) {
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

    /** The units that keep roll call templates of their own: a company, and a section ({@see TemplateAuthority}). */
    public const TEMPLATE_OWNERS = [UnitType::Company, UnitType::Section];

    /**
     * Whether the user may manage the templates of at least one unit, by the rule {@see TemplateAuthority} applies to
     * each: an Admin, or someone who commands a company or a section, or a unit with one beneath it (a battalion, a
     * platoon). For the shell's Templates link; each write is checked again. Real authority, as a write is.
     */
    public function managesAnyTemplates(User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $commanded = $this->commandScope($user)->flip();

        return $this->units->tree()->contains(fn (Unit $unit) => $commanded->has($unit->id) && in_array($unit->type, self::TEMPLATE_OWNERS, true));
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
        if ($this->isAdmin($user)) {
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
     * a cached trooper whose standing is on {@see TrooperStatus::serving()}. Three sources, none ever in
     * {@see writeScope()}:
     *
     *  - their staff grants. A grant is staff scope for a *member* of the unit: once the holder leaves (the roster sync
     *    flips them `inactive`), or was never linked to a milpac at all, every grant they hold stops counting on the next
     *    read, with no one having to remember to revoke it. The rows themselves are kept, so the staff grants page can
     *    show them as lapsed and an Admin can revoke them on the record; a holder who returns to a serving roster gets
     *    them back.
     *  - the units they oversee (`unit_oversights`): read exactly as a grant on each, by
     *    trooper, so it holds from their first sign-in. Cleared, or the overseer no longer serving, it is gone.
     *  - the regiment, for Regimental HQ staff: a trooper placed directly on the regiment node, where the org import
     *    places every Regimental HQ billet (`CommandBillet::headquartersOf()`), whose position is still a Regimental HQ
     *    billet as Cav lists it now (the `admin_billets` row the import keeps for each, any status,
     *    {@see AdminBillet::inRegimentalHq()}). The roster sync leaves a trooper's placement as it was when their new
     *    billet maps nowhere, so the placement alone could outlive the billet. Holding groups and staff departments are
     *    their own units under the regiment, so their members never match.
     *
     * Admins never reach here, by flag or by billet, so the regiment only matters for Regimental HQ staff whose billet is
     * not (or no longer) an approved Admin billet. This placement rule is the read scope's only: Admin by billet does
     * not look at placement. The bulk realScopes() (#498) must apply the same three sources.
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
            ->merge($this->oversights->unitsOverseenBy($user))
            ->push($this->regimentPlacing($trooper))
            ->filter()
            ->values();
    }

    /**
     * The regiment when the trooper is placed directly on it and still holds a Regimental HQ billet (Regimental HQ
     * staff), else null.
     */
    private function regimentPlacing(Trooper $trooper): ?Unit
    {
        $placed = $trooper->primary_section_id === null ? null : $this->units->find($trooper->primary_section_id);
        if (! self::isRegimentalHqPlacement($placed)) {
            return null;
        }

        return $this->regimentalHqPositions(collect([$trooper]))->has((int) $trooper->cav_position_id) ? $placed : null;
    }

    /**
     * The positions of `$troopers` that Cav lists under Regimental HQ now ({@see AdminBillet::inRegimentalHq()}), flipped
     * for lookup. The org import keeps an `admin_billets` row for every Regimental HQ billet and checks its group on
     * every run, so a position with no row, or one Cav moved to another group, is not one. One query, none for no
     * positions.
     *
     * @param  Collection<int, Trooper>  $troopers
     * @return Collection<int, int>
     */
    private function regimentalHqPositions(Collection $troopers): Collection
    {
        $positions = $troopers->pluck('cav_position_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return $this->adminBillets->forPositions($positions)
            ->filter(fn (AdminBillet $billet) => $billet->inRegimentalHq())
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->flip();
    }

    /**
     * Who reads the whole regiment as Regimental HQ staff right now: the serving troopers placed directly on the regiment
     * node who still hold a Regimental HQ billet, by username. The same tests {@see readOnlyUnitsWhileServing()} and
     * {@see realScopes()} apply one trooper at a time ({@see TrooperStatus::serving()}, {@see isRegimentalHqPlacement()}
     * and {@see regimentalHqPositions()}), so the Staff grants page lists exactly the people this class gives the
     * regiment to. A trooper with no portal account yet is listed too: they read it from their first sign-in. Three
     * queries.
     *
     * @return Collection<int, Trooper>
     */
    public function regimentalHqStaff(): Collection
    {
        $regimentIds = $this->units->tree()
            ->filter(fn (Unit $unit) => self::isRegimentalHqPlacement($unit))
            ->pluck('id');
        $placed = $this->troopers->servingPlacedIn($regimentIds);
        $positions = $this->regimentalHqPositions($placed);

        return $placed->filter(fn (Trooper $trooper) => $positions->has((int) $trooper->cav_position_id))->values();
    }

    /**
     * Whether `$user` is Regimental HQ staff right now: serving, placed directly on the regiment node, on a position
     * that is still a Regimental HQ billet. The same test the read scope applies ({@see regimentPlacing()}). Two or three
     * queries.
     */
    public function isRegimentalHqStaff(User $user): bool
    {
        $trooper = $user->cav_id === null ? null : $this->troopers->byCavId([(int) $user->cav_id])->get((int) $user->cav_id);

        return ($trooper?->status?->isServing() ?? false) && $this->regimentPlacing($trooper) !== null;
    }

    /**
     * Whether `$user` may set or clear the game of `$unit`: an Admin, Regimental HQ staff ({@see isRegimentalHqStaff()}),
     * or the serving overseer of the unit that picks the game for it (the unit itself at battalion level or above, else
     * the battalion-level unit above it, {@see Unit::setsOwnGame()}). Under a view-as preview the request is read-only
     * anyway (the ReadOnlyWhileViewingAs middleware), so this asks only about the person.
     */
    public function maySetUnitGame(User $user, Unit $unit): bool
    {
        if ($this->isAdmin($user) || $this->isRegimentalHqStaff($user)) {
            return true;
        }

        $picker = $this->gamePickerOf($unit);

        return $picker !== null && $this->oversights->unitsOverseenBy($user)->contains('id', $picker->id);
    }

    /** The unit whose game `$unit` plays by rule: itself when it picks its own, else the nearest one above that does. */
    public function gamePickerOf(Unit $unit): ?Unit
    {
        for ($at = $unit, $depth = 0; $at !== null && $depth < 10; $depth++) {
            $parent = $at->parent_id === null ? null : $this->units->find((int) $at->parent_id);
            if ($at->setsOwnGame($parent)) {
                return $at;
            }
            $at = $parent;
        }

        return null;
    }

    /**
     * Whether a trooper placed on `$unit` is Regimental HQ staff: placed on the regiment node itself, nothing below it.
     * The Regimental HQ read scope's placement rule; Admin by billet ({@see isAdminByBillet()}) does not use it.
     */
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
     * Whether the user should be treated as an Admin on a read (Story 1.13): whether they are one
     * ({@see isAdmin()}, the flag or an approved billet), unless a view-as preview of a non-Admin is
     * active — then the preview shows what that leader sees, Admin-only screens included. Narrows
     * only: a non-Admin never becomes one here. Writes keep checking {@see isAdmin()}; a preview
     * refuses them anyway.
     */
    public function actsAsAdmin(User $user): bool
    {
        return $this->isAdmin($user) && ($this->activeViewAs($user)?->admin ?? true);
    }

    /**
     * Whether the user holds a leadership billet as a read sees it: they command at least one unit (a synced billet,
     * an acting lead, or Admin), never through a staff grant alone. A preview narrows this and never raises it: only
     * someone who really commands is a leader in one. A unit preview by them is that unit's leader; a person preview
     * is that person's own command. A grant holder previewing a unit their grant reaches stays a non-leader. Not
     * {@see writeScope()}: a person preview narrows the Admin's tree to the person's read scope, grants included, so a
     * grant holder would pass.
     */
    public function leads(User $user): bool
    {
        $commands = $this->commandScope($user)->isNotEmpty();
        $preview = $this->activeViewAs($user);
        if ($preview === null || ! $commands) {
            return $commands;
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
     * Whether `$trooper` is the user's own milpac (decided 2026-10-28): every signed-in trooper may open their own
     * milpac page read-only, with no command and no grant, and see their own DCSs and LORs on it. Only for the person
     * really signed in, as themselves: never while they act as someone ({@see RealActor}), so acting as a person opens
     * their page only as far as the actor's command reaches it and never shows their DCSs through this rule, and never
     * during a view-as preview, which shows what its target would see. It widens nothing else: {@see canSee()} is
     * unchanged, so the roster and every other page still list only the scope, and only the milpac page, its counseling
     * downloads, the staff member's own practice entries and the trooper search's own row ask this.
     */
    public function isOwnTrooper(User $user, Trooper $trooper): bool
    {
        $own = $trooper->cav_id === null ? null : $this->ownCavId($user);

        return $own !== null && $own === (int) $trooper->cav_id;
    }

    /**
     * The cav id whose milpac {@see isOwnTrooper()} opens for the user: their own, while they are signed in as
     * themselves and act as nobody and view as nothing; else null.
     */
    public function ownCavId(User $user): ?int
    {
        if ($user->cav_id === null || $this->real->get() !== null || $this->activeViewAs($user) !== null) {
            return null;
        }

        return (int) $user->cav_id;
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
            ViewAsKind::User => $this->isAdmin($viewer) ? $this->asUser($viewer, $target) : null,
            ViewAsKind::Trooper => $this->isAdmin($viewer) ? $this->asTrooper($target) : null,
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
            // A previewed billet Admin shows the Admin screens, as a previewed flag Admin does: the viewer is one anyway.
            admin: $this->isAdmin($user),
        );
    }

    /**
     * A serving trooper who has never signed in, as the account they would have: unsaved, never an Admin (by flag or by
     * billet: the preview stays the narrower of the two), with no grants,
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
        $account->notAdminByBillet = true;

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
