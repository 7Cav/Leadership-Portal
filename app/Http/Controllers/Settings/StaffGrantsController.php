<?php

namespace App\Http\Controllers\Settings;

use App\Commands\PortalRoleGrants\GrantStaffScope;
use App\Commands\PortalRoleGrants\GrantTargetIsAdminException;
use App\Commands\PortalRoleGrants\RevokeStaffScope;
use App\Http\Controllers\Controller;
use App\Models\PortalRoleGrant;
use App\Repositories\PortalRoleGrantRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff grants settings (Story 1.12, AD-16). Admin manages non-admin staff scope
 * (`portal_role_grants`) that the roster can't imply — S-shop staff, dual-hats. Reads
 * `PortalRoleGrantRepository`/`UnitRepository`/`UserRepository` directly per the slim,
 * Laravel-idiomatic layer model (mirrors `OrgStructureController`). Writes stage through
 * `GrantStaffScope`/`RevokeStaffScope` (AD-16).
 */
class StaffGrantsController extends Controller
{
    public function __construct(
        private readonly PortalRoleGrantRepository $grants,
        private readonly UnitRepository $units,
        private readonly UserRepository $users,
    ) {}

    /**
     * Everyone authenticated may view the list (matches `OrgStructureController`'s read/write
     * split); only the Admin flag gates authoring (checked client-side for the hidden controls,
     * and again below on write — AD-6).
     */
    public function index(Request $request): Response
    {
        $grants = $this->grants->all()->map(fn (PortalRoleGrant $grant) => [
            'id' => $grant->id,
            'user_id' => $grant->user_id,
            'username' => $grant->user->username,
            'unit_id' => $grant->unit_id,
            'unit_name' => $grant->unit->name,
            'created_at' => $grant->created_at->toIso8601String(),
        ])->values();

        $units = $this->units->tree()->map(fn ($unit) => [
            'id' => $unit->id,
            'name' => $unit->name,
        ])->values();

        $users = $this->users->nonAdminUsers()->map(fn ($user) => [
            'id' => $user->id,
            'username' => $user->username,
        ])->values();

        return Inertia::render('Settings/StaffGrants', [
            'grants' => $grants,
            'units' => $units,
            'users' => $users,
            'isAdmin' => (bool) $request->user()->is_admin,
        ]);
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
     * already have been revoked from another tab.
     */
    public function destroy(Request $request, PortalRoleGrant $grant, RevokeStaffScope $command): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only an Admin may manage staff grants.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        // Plain FK columns, not relations — reading them off `$grant` doesn't trigger lazy
        // loading (AD-2's `preventLazyLoading()` outside production).
        $target = $this->users->find($grant->user_id);
        $unit = $this->units->find($grant->unit_id);

        $command->handle($request->user(), $target, $unit, $data['reason']);

        return redirect()->route('settings.staff-grants');
    }
}
