<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Troopers\InvalidTrooperPlacementException;
use App\Commands\Troopers\PlaceTrooperInSection;
use App\Commands\Troopers\PlaceTrooperInSectionHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Manual placement of an unmapped trooper (Story 2.7). A thin dispatcher: the audited write and the
 * domain rules live in {@see PlaceTrooperInSectionHandler}, which also re-checks the Admin gate (AD-6).
 * The gate is checked here first, like every other Settings write, and before anything about the
 * trooper is looked up, so a non-Admin learns nothing about which trooper ids exist. The trooper id is
 * taken as a plain number rather than route-model-bound for the same reason: binding would answer 404
 * before the gate ran. `section_id` keeps its name but takes any unit: a section, an HQ node or a group (2026-10-24).
 * A rule the handler enforces (no such trooper, no such unit, already placed,
 * departed) comes back as a form error, not a 500.
 */
class TrooperPlacementController extends Controller
{
    public function store(Request $request, int $trooper, PlaceTrooperInSectionHandler $handler, ScopeResolver $scope): RedirectResponse
    {
        if (! $scope->isAdmin($request->user())) {
            abort(403, 'Only an Admin may place a trooper into a unit.');
        }

        $data = $request->validate([
            'section_id' => ['required', 'integer', 'exists:units,id'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'section_id.required' => 'Choose the section to place the trooper in.',
            'section_id.exists' => 'That section no longer exists. Reload the page and choose another.',
            'reason.required' => 'Give a reason for the record.',
            'reason.max' => 'Keep the reason to :max characters.',
        ]);

        try {
            $handler->handle($request->user(), new PlaceTrooperInSection($trooper, (int) $data['section_id'], $data['reason']));
        } catch (InvalidTrooperPlacementException $e) {
            throw ValidationException::withMessages(['placement' => $e->getMessage()]);
        }

        return redirect()->route('settings.org-structure');
    }
}
