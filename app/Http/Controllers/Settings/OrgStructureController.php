<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Units\CreateUnit;
use App\Commands\Units\DeleteUnit;
use App\Commands\Units\ImportOrgStructure;
use App\Commands\Units\InvalidUnitPlacementException;
use App\Commands\Units\OrgStructureImportFailedException;
use App\Commands\Units\UnitHasDependentsException;
use App\Commands\Units\UpdateUnit;
use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\TrooperRepository;
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
    /** Most unplaced troopers sent per visit; a fresh install can put the whole roster here. */
    private const UNPLACED_LIMIT = 100;

    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
    ) {}

    /**
     * Everyone authenticated may view the tree (AC #3); only the Admin flag gates authoring
     * (checked client-side for the hidden control, and again below on write — AC #5).
     */
    public function index(Request $request, ScopeResolver $scope): Response
    {
        $tree = $this->units->tree();
        // Troopers assigned to each section, rolled up every branch: a unit's count is the troopers in
        // any section under its path (the same subtree rule strength uses, AD-18, AD-21). The prefix
        // test relies on the AD-18 convention that every `path` ends in "/", so "1/2/" can never match
        // "1/20/".
        $bySection = $this->troopers->countsBySection();
        $sectionPaths = $tree->whereIn('id', $bySection->keys()->all())->pluck('path', 'id');

        $units = $tree->map(fn ($unit) => [
            'id' => $unit->id,
            'parent_id' => $unit->parent_id,
            'type' => $unit->type->value,
            'name' => $unit->name,
            'callsign' => $unit->callsign,
            'path' => $unit->path,
            'trooper_count' => $bySection
                ->filter(fn (int $count, int $sectionId) => str_starts_with($sectionPaths->get($sectionId, ''), $unit->path))
                ->sum(),
        ])->values();

        // Follows a view-as preview (Story 1.13): authoring controls hide as they would for the
        // previewed leader. The write gates below keep reading the real flag.
        $isAdmin = $scope->actsAsAdmin($request->user());

        return Inertia::render('Settings/OrgStructure', [
            'units' => $units,
            'isAdmin' => $isAdmin,
            // Troopers the sync could not place (2.6). Admin-tier only: a cached trooper's reported
            // billet is not part of any leader's scope yet, so everyone else gets null and the panel
            // is absent. Sent as props only to an Admin, never merely hidden by the page (AD-8, AD-6).
            'unplacedTroopers' => $isAdmin ? $this->troopers->unplaced(self::UNPLACED_LIMIT)->all() : null,
            // The true total, so the heading stays honest when the list above is capped.
            'unplacedTotal' => $isAdmin ? $this->troopers->unplacedCount() : null,
            // The outcome of an import just run (story 2.9), flashed by `import()` for one render.
            'importResult' => $request->session()->get('org_import'),
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
     * `UpdateUnit`'s docblock. `UpdateUnit` stages the re-parent through `MoveUnit` and the
     * rename in one transaction, each with its own audit row, so the edit applies fully or not
     * at all, and a rename with no `parent_id` change never touches `UnitRepository::move()`.
     */
    public function update(Request $request, Unit $unit, UpdateUnit $command): RedirectResponse
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
            $command->handle($request->user(), $unit, $data['name'], $data['callsign'] ?? null, $data['parent_id'] ?? null);
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

    /**
     * Import the chain of command and the billet → section mapping from the Cav position groups
     * (story 2.9). Admin-only, server-side (AD-6). A failed import (Cav unreachable or unusable,
     * or no line companies) surfaces as an `errors.import` message on the page; nothing was
     * written in that case (the Command fetches and parses before it writes).
     */
    public function import(Request $request, ImportOrgStructure $command): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only an Admin may edit the chain of command.');
        }

        try {
            $result = $command->handle($request->user());
        } catch (OrgStructureImportFailedException $e) {
            throw ValidationException::withMessages(['import' => $e->getMessage()]);
        }

        return redirect()->route('settings.org-structure')->with('org_import', [
            'unitsCreated' => $result->unitsCreated,
            'mappingsCreated' => $result->mappingsCreated,
            'mappingsDiffering' => $result->mappingsDiffering,
            'commandMappingsCreated' => $result->commandMappingsCreated,
            'commandMappingsRepointed' => $result->commandMappingsRepointed,
            'commandMappingsRemoved' => $result->commandMappingsRemoved,
            'skippedTitles' => $result->skippedTitles,
        ]);
    }
}
