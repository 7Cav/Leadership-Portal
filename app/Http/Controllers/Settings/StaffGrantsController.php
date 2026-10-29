<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\AdminBillets\AdminBilletForbiddenException;
use App\Commands\AdminBillets\DecideAdminBillet;
use App\Commands\AdminBillets\DecideAdminBilletHandler;
use App\Commands\AdminBillets\InvalidAdminBilletDecisionException;
use App\Commands\PortalRoleGrants\GrantStaffScope;
use App\Commands\PortalRoleGrants\GrantTargetIsAdminException;
use App\Commands\PortalRoleGrants\RevokeStaffScope;
use App\Http\Controllers\Controller;
use App\Models\AdminBillet;
use App\Models\AdminBilletStatus;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\AdminBilletRepository;
use App\Repositories\PortalRoleGrantRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff grants settings (Story 1.12, AD-16). Admin manages non-admin staff scope
 * (`portal_role_grants`) that the roster can't imply — S-shop staff, dual-hats. Reads
 * `PortalRoleGrantRepository`/`UnitRepository`/`UserRepository` directly per the slim,
 * Laravel-idiomatic layer model (mirrors `OrgStructureController`), plus `TrooperRepository` to flag
 * a grant whose holder is no longer serving. Writes stage through
 * `GrantStaffScope`/`RevokeStaffScope` (AD-16).
 *
 * It also lists the Admin billets (policy, 2026-10-28): the approved ones, whose holders are Admins, and
 * below them the ones waiting for a decision or rejected, all decided through `DecideAdminBillet`. A small note under
 * the approved ones names the Regimental HQ staff who read the whole regiment from their placement.
 */
class StaffGrantsController extends Controller
{
    public function __construct(
        private readonly PortalRoleGrantRepository $grants,
        private readonly UnitRepository $units,
        private readonly UserRepository $users,
        private readonly TrooperRepository $troopers,
        private readonly AdminBilletRepository $adminBillets,
        private readonly ScopeResolver $scope,
    ) {}

    /**
     * Everyone authenticated may view the list (matches `OrgStructureController`'s read/write
     * split); only the Admin flag gates authoring (checked client-side for the hidden controls,
     * and again below on write — AD-6). The target-user picker lists every non-admin username, so
     * it is sent only to someone acting as Admin; anyone else, an Admin previewing a leader
     * included, gets an empty list (AD-6, AD-8). The Admin billets and the Regimental HQ staff are sent the same way.
     */
    public function index(Request $request, ScopeResolver $scope): Response
    {
        $isAdmin = $scope->actsAsAdmin($request->user());
        $all = $this->grants->all();
        $tree = $this->units->tree();
        $byId = $tree->keyBy('id');

        // A grant only counts in scope while its holder serves (ScopeResolver). One that has lapsed
        // stays listed and flagged rather than hidden: the row still exists, and an Admin needs to
        // see it to revoke it on the record — or to know it comes back if the holder returns.
        $holderCavIds = $all->pluck('user.cav_id')->filter()->map(fn ($cavId) => (int) $cavId)->unique()->values()->all();
        $serving = $this->troopers->servingCavIds($holderCavIds)->flip();

        $grants = $all->map(fn (PortalRoleGrant $grant) => [
            'id' => $grant->id,
            'user_id' => $grant->user_id,
            'username' => $grant->user->username,
            'holder_serving' => $grant->user->cav_id !== null && $serving->has($grant->user->cav_id),
            'unit_id' => $grant->unit_id,
            'unit_name' => $grant->unit->name,
            'unit_label' => $this->label($grant->unit, $byId),
            'created_at' => $grant->created_at->toIso8601String(),
        ])->values();

        $units = $tree->map(fn (Unit $unit) => [
            'id' => $unit->id,
            'name' => $unit->name,
            'label' => $this->label($unit, $byId),
        ])->values();

        // Every user a grant would mean something for: an Admin by flag or by billet already resolves the full tree.
        $candidates = $isAdmin ? $this->users->nonAdminUsers() : collect();
        $admins = $scope->adminIdsAmong($candidates)->flip();
        $users = $candidates
            ->reject(fn ($user) => $admins->has($user->id))
            ->map(fn ($user) => [
                'id' => $user->id,
                'username' => $user->username,
            ])->values();

        // Regimental HQ staff read the whole regiment from their placement on it, not a grant (ScopeResolver, #499), so
        // nothing else on this page shows it. Listed from the same rule, read-only and only to someone acting as Admin,
        // like the username picker. Being an Admin is the billet's, listed with the Admin billets instead.
        $regimentalHq = ($isAdmin ? $scope->regimentalHqStaff() : collect())->map(fn (Trooper $trooper) => [
            'id' => $trooper->id,
            'name' => $trooper->display_name,
            'billet' => $trooper->billet,
        ])->values();

        // Approved billets on top (their holders are Admins), the rest below for a decision.
        [$approved, $undecided] = $isAdmin
            ? collect($this->adminBilletRows($request))->partition(fn (array $row) => $row['status'] === AdminBilletStatus::Approved->value)
            : [collect(), collect()];

        return Inertia::render('Settings/StaffGrants', [
            'grants' => $grants,
            'regimentalHq' => $regimentalHq,
            'adminViaBillet' => $approved->values()->all(),
            'adminBillets' => $undecided->values()->all(),
            'units' => $units,
            'users' => $users,
            // Follows a view-as preview (Story 1.13): authoring controls hide as they would for the
            // previewed leader. The write gates below keep reading the real flag.
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Every billet the import or the roster sync has seen in an Admin billet group, for the two lists: the names Cav
     * shows now, the ones an Admin approved when they differ (`approvedAs`), who holds it, whether its group can still
     * make an Admin (`adminGroup`), who decided last and whether the viewer holds it themselves (they cannot decide on
     * it, {@see DecideAdminBilletHandler}). Pending first, then rejected, then approved.
     *
     * @return list<array<string, mixed>>
     */
    private function adminBilletRows(Request $request): array
    {
        $billets = $this->adminBillets->all();
        $holders = $this->troopers->servingHoldingPositions($billets->pluck('cav_position_id')->map(fn ($id) => (int) $id)->all());
        $deciders = $this->users->usernamesByIds($billets->pluck('decided_by')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all());
        $cavId = $request->user()->cav_id;
        $own = $cavId === null ? null : $this->troopers->byCavId([(int) $cavId])->get((int) $cavId)?->cav_position_id;

        return $billets->map(fn (AdminBillet $billet) => [
            'id' => $billet->id,
            'title' => $billet->proposed_title,
            'group' => $billet->proposed_group_title,
            'status' => $billet->status->value,
            // Approved once under other names (renamed or moved since): those names, shown under the ones now.
            'approvedAs' => $billet->title !== null && $billet->renamed()
                ? ['title' => $billet->title, 'group' => $billet->group_title]
                : null,
            // An approved billet whose group is no longer an Admin billet group makes nobody an Admin.
            'adminGroup' => AdminBillet::isAdminGroup($billet->proposed_group_title),
            'holders' => $holders->get($billet->cav_position_id, collect())->map(fn (Trooper $trooper) => $trooper->display_name)->values()->all(),
            'own' => $own !== null && (int) $own === $billet->cav_position_id,
            'decidedAt' => $billet->decided_at?->toIso8601String(),
            'decidedBy' => $billet->decided_by === null ? null : $deciders->get($billet->decided_by),
        ])->values()->all();
    }

    /**
     * The unit's chain of names below the top of the tree (`Alpha Company › 1st Platoon`), so two
     * units with the same name (a "1st Platoon" under Alpha and one under Bravo) read apart in the
     * picker and the grants list. The root is left off because every option would repeat it; a
     * root itself shows its own name.
     *
     * @param  Collection<int, Unit>  $byId
     */
    private function label(Unit $unit, Collection $byId): string
    {
        $chain = collect(explode('/', trim($unit->path, '/')))
            ->map(fn (string $id) => $byId->get((int) $id))
            ->filter();

        if ($chain->count() > 1) {
            $chain = $chain->slice(1);
        }

        return $chain->isEmpty() ? $unit->name : $chain->pluck('name')->implode(' › ');
    }

    /**
     * Grant a non-admin staff user scope at a chosen node (AC #1). The Admin gate is enforced
     * here, server-side, regardless of what the UI shows (AC #4, AD-6).
     */
    public function store(Request $request, GrantStaffScope $command): RedirectResponse
    {
        if (! $this->scope->isAdmin($request->user())) {
            abort(403, 'Only an Admin may manage staff grants.');
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'user_id.required' => 'Choose the user to grant.',
            'user_id.exists' => 'That user no longer exists. Reload the page and choose another.',
            'unit_id.required' => 'Choose the unit to grant.',
            'unit_id.exists' => 'That unit no longer exists. Reload the page and choose another.',
            'reason.required' => 'A reason is required to give a grant.',
            'reason.max' => 'Keep the reason to :max characters.',
        ]);

        // `user_id`/`unit_id` were already confirmed to exist by the validation rules above, so
        // a null find() here would mean that guarantee broke, not a user error.
        $target = $this->users->find($data['user_id']);
        $unit = $this->units->find($data['unit_id']);

        try {
            $command->handle($request->user(), $target, $unit, $data['reason']);
        } catch (GrantTargetIsAdminException $e) {
            throw ValidationException::withMessages(['user_id' => $e->getMessage()]);
        }

        return redirect()->route('settings.staff-grants');
    }

    /**
     * Revoke a grant (AC #2). A no-op if the grant no longer exists is not an error — it may
     * already have been revoked from another tab. The Admin gate runs before the lookup, so a
     * non-Admin gets the same 403 whether or not the grant exists.
     */
    public function destroy(Request $request, int $grant, RevokeStaffScope $command): RedirectResponse
    {
        if (! $this->scope->isAdmin($request->user())) {
            abort(403, 'Only an Admin may manage staff grants.');
        }

        $grant = $this->grants->findById($grant) ?? abort(404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'A reason is required to revoke a grant.',
            'reason.max' => 'Keep the reason to :max characters.',
        ]);

        // Plain FK columns, not relations — reading them off `$grant` doesn't trigger lazy
        // loading (AD-2's `preventLazyLoading()` outside production).
        $target = $this->users->find($grant->user_id);
        $unit = $this->units->find($grant->unit_id);

        $command->handle($request->user(), $target, $unit, $data['reason']);

        return redirect()->route('settings.staff-grants');
    }

    /** Approve a billet as an Admin billet (policy, 2026-10-28). */
    public function approveAdminBillet(Request $request, int $billet, DecideAdminBilletHandler $handler): RedirectResponse
    {
        return $this->decideAdminBillet($request, $billet, true, $handler);
    }

    /**
     * Reject a billet as an Admin billet, or revoke an approved one: its holder is not an Admin until a name changes and
     * it is approved again.
     */
    public function rejectAdminBillet(Request $request, int $billet, DecideAdminBilletHandler $handler): RedirectResponse
    {
        return $this->decideAdminBillet($request, $billet, false, $handler);
    }

    /**
     * The Admin gate comes first, so a non-Admin gets the same 403 whether or not the billet exists (AD-6). A refused
     * decision (the decider's own billet, one already decided so, names not seen yet) is a form error on `adminBillet`.
     */
    private function decideAdminBillet(Request $request, int $billet, bool $approve, DecideAdminBilletHandler $handler): RedirectResponse
    {
        if (! $this->scope->isAdmin($request->user())) {
            abort(403, 'Only an Admin may confirm or reject an Admin billet.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => $approve ? 'A reason is required to approve a billet.' : 'A reason is required to reject a billet.',
            'reason.max' => 'Keep the reason to :max characters.',
        ]);

        try {
            $handler->handle($request->user(), new DecideAdminBillet($billet, $approve, $data['reason']));
        } catch (AdminBilletForbiddenException $e) {
            abort(403, $e->getMessage());
        } catch (InvalidAdminBilletDecisionException $e) {
            throw ValidationException::withMessages(['adminBillet' => $e->getMessage()]);
        }

        return redirect()->route('settings.staff-grants');
    }
}
