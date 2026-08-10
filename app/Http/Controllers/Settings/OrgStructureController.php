<?php

namespace App\Http\Controllers\Settings;

use App\Commands\Units\CreateUnit;
use App\Commands\Units\DeleteUnit;
use App\Commands\Units\InvalidUnitPlacementException;
use App\Commands\Units\MoveUnit;
use App\Commands\Units\UnitHasDependentsException;
use App\Commands\Units\UpdateUnit;
use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\UnitRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Org structure settings (Story 1.8, AD-18). Reads `UnitRepository` directly per the slim,
 * Laravel-idiomatic layer model — no `ReadServices/` layer exists in this codebase (deviates
 * from the issue's Implementation Notes, which predate that consolidation; see the story
 * artifact). Writes stage through `CreateUnit` (AD-15).
 */
class OrgStructureController extends Controller
{
    public function __construct(private readonly UnitRepository $units) {}

    /**
     * Everyone authenticated may view the tree (AC #3); only the Admin flag gates authoring
     * (checked client-side for the hidden control, and again below on write — AC #5).
     */
    public function index(Request $request): Response
    {
        $units = $this->units->tree()->map(fn ($unit) => [
            'id' => $unit->id,
            'parent_id' => $unit->parent_id,
            'type' => $unit->type->value,
            'name' => $unit->name,
            'callsign' => $unit->callsign,
            'path' => $unit->path,
            // Placeholder until Epic 2's roster sync populates `troopers.primary_section_id`
            // (per the issue's own Implementation Notes on this story's scope).
            'trooper_count' => 0,
        ])->values();

        return Inertia::render('Settings/OrgStructure', [
            'units' => $units,
            'isAdmin' => (bool) $request->user()->is_admin,
        ]);
    }

    /**
     * Add a node under a chosen parent (AC #2). The Admin gate is enforced here, server-side,
     * regardless of what the UI shows (AC #5, AD-6) — `Authorization/`'s ScopeResolver doesn't
     * land until Story 1.11, so this checks the `is_admin` flag (AD-7) directly.
     */
    public function store(Request $request, CreateUnit $command): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only an Admin may edit the chain of command.');
        }

        $data = $request->validate([
            'type' => ['required', Rule::enum(UnitType::class)],
            'name' => ['required', 'string', 'max:255'],
            'callsign' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        try {
            $command->handle(
                $request->user(),
                UnitType::from($data['type']),
                $data['name'],
                $data['callsign'] ?? null,
                $data['parent_id'] ?? null,
            );
        } catch (InvalidUnitPlacementException $e) {
            // Echelon ordering is a domain rule the request-shape validation above can't
            // express (it needs the parent's own type) — surfaced as a form error on `type`
            // rather than a 500, so the Inertia form's `errors.type` renders it.
            throw ValidationException::withMessages(['type' => $e->getMessage()]);
        }

        return redirect()->route('settings.org-structure');
    }

    /**
     * Rename a node and/or re-parent it (AC #1, #2). Echelon (`type`) isn't editable here — see
     * `UpdateUnit`'s docblock. Both are staged through their own Commands so a rename with no
     * `parent_id` change never touches `UnitRepository::move()`. `MoveUnit` runs first
     * deliberately: it validates before it ever writes (a no-op or a thrown
     * `InvalidUnitPlacementException`), whereas `UpdateUnit` always writes — renaming first
     * would let a refused re-parent leave the rename committed behind a 422.
     */
    public function update(Request $request, Unit $unit, UpdateUnit $renameUnit, MoveUnit $moveUnit): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only an Admin may edit the chain of command.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'callsign' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        try {
            $moveUnit->handle($request->user(), $unit, $data['parent_id'] ?? null);
            $renameUnit->handle($request->user(), $unit, $data['name'], $data['callsign'] ?? null);
        } catch (InvalidUnitPlacementException $e) {
            // Cycle and echelon-rank violations are domain rules the request-shape validation
            // above can't express — surfaced as a form error on `parent_id` (AC #3).
            throw ValidationException::withMessages(['parent_id' => $e->getMessage()]);
        }

        return redirect()->route('settings.org-structure');
    }

    /**
     * Remove a node (AC #4). Refused, not a raw DB constraint error, when children remain.
     */
    public function destroy(Request $request, Unit $unit, DeleteUnit $command): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only an Admin may edit the chain of command.');
        }

        try {
            $command->handle($request->user(), $unit);
        } catch (UnitHasDependentsException $e) {
            throw ValidationException::withMessages(['delete' => $e->getMessage()]);
        }

        return redirect()->route('settings.org-structure');
    }
}
