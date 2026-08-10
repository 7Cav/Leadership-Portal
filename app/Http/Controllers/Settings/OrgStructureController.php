<?php

namespace App\Http\Controllers\Settings;

use App\Commands\Units\CreateUnit;
use App\Http\Controllers\Controller;
use App\Models\UnitType;
use App\Repositories\UnitRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            throw new HttpException(403, 'Only an Admin may edit the chain of command.');
        }

        $data = $request->validate([
            'type' => ['required', Rule::enum(UnitType::class)],
            'name' => ['required', 'string', 'max:255'],
            'callsign' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $command->handle(
            UnitType::from($data['type']),
            $data['name'],
            $data['callsign'] ?? null,
            $data['parent_id'] ?? null,
        );

        return redirect()->route('settings.org-structure');
    }
}
