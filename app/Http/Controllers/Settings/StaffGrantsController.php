<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\PortalRoleGrants\GrantStaffScope;
use App\Commands\PortalRoleGrants\GrantTargetIsAdminException;
use App\Commands\PortalRoleGrants\RevokeStaffScope;
use App\Http\Controllers\Controller;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\Unit;
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
 */
class StaffGrantsController extends Controller
{
    public function __construct(
        private readonly PortalRoleGrantRepository $grants,
        private readonly UnitRepository $units,
        private readonly UserRepository $users,
        private readonly TrooperRepository $troopers,
    ) {}

    /**
     * Everyone authenticated may view the list (matches `OrgStructureController`'s read/write
     * split); only the Admin flag gates authoring (checked client-side for the hidden controls,
     * and again below on write — AD-6). The target-user picker lists every non-admin username, so
     * it is sent only to someone acting as Admin; anyone else, an Admin previewing a leader
     * included, gets an empty list (AD-6, AD-8). The Regimental HQ staff list is sent the same way.
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

        $users = $isAdmin
            ? $this->users->nonAdminUsers()->map(fn ($user) => [
                'id' => $user->id,
                'username' => $user->username,
            ])->values()
            : collect();

        // Regimental HQ staff read the whole regiment from their placement, not a grant (ScopeResolver), so nothing else
        // on this page or in the audit log shows it. Listed from the same rule the scope uses, read-only and only to
        // someone acting as Admin, like the username picker.
        $regimentalHq = $isAdmin
            ? $scope->regimentalHqStaff()->map(fn (Trooper $trooper) => [
                'id' => $trooper->id,
                'name' => $trooper->display_name,
                'billet' => $trooper->billet,
            ])->values()
            : collect();

        return Inertia::render('Settings/StaffGrants', [
            'grants' => $grants,
            'regimentalHq' => $regimentalHq,
            'units' => $units,
            'users' => $users,
            // Follows a view-as preview (Story 1.13): authoring controls hide as they would for the
            // previewed leader. The write gates below keep reading the real flag.
            'isAdmin' => $isAdmin,
        ]);
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
        if (! $request->user()->is_admin) {
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
        if (! $request->user()->is_admin) {
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
}
