<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Http\Controllers\Controller;
use App\Jobs\SyncLoasJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The manual leave-sync trigger (Story 4.2), mirroring the roster one: no view here, it only authorizes and
 * dispatches. The screen that hosts the control comes with the leave views.
 */
class LoaSyncController extends Controller
{
    public function __construct(private readonly ScopeResolver $scope) {}

    /**
     * Dispatch `SyncLoasJob` through the same queued path the scheduler uses, recording the user as the
     * trigger. `full` reads a year of threads instead of only the new ones, for when an old post was edited
     * or a section was added. Admin only, checked here (AD-6), and not while viewing as someone else
     * (`actsAsAdmin`, AD-8): a write is refused in view-as.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! $this->scope->actsAsAdmin($request->user())) {
            abort(403, 'Only an Admin may start an LOA sync by hand.');
        }

        SyncLoasJob::dispatch($request->user()->id, $request->boolean('full'));

        return back();
    }
}
