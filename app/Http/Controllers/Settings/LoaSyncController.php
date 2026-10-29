<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Http\Controllers\Controller;
use App\Jobs\SyncLoasJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The manual leave-sync trigger (Story 4.2), mirroring the roster one: no view here, it only authorizes and
 * dispatches, and says what it did in a notice. The screen that hosts the control comes with the leave views.
 */
class LoaSyncController extends Controller
{
    public function __construct(private readonly ScopeResolver $scope) {}

    /**
     * Dispatch `SyncLoasJob` through the same queued path the scheduler uses, recording the user as the
     * trigger. `full` reads a year of threads instead of only the new ones, for when an old post was edited
     * or a section was added. Admin only, checked here (AD-6), and not while viewing as someone else
     * (`actsAsAdmin`, AD-8): a write is refused in view-as.
     *
     * A sync of the same kind already queued or running would swallow the dispatch (the job is unique), and one
     * already running may have read past what the Admin wanted. So the request becomes the one follow-up run,
     * which runs after it; if that is queued too, it is still to start and will pick the request up. Either way
     * the page says which, in a notice flashed for one render.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! $this->scope->actsAsAdmin($request->user())) {
            abort(403, 'Only an Admin may start an LOA sync by hand.');
        }

        $full = $request->boolean('full');
        $job = new SyncLoasJob($request->user()->id, $full);

        if (! $job->isAlreadyQueued()) {
            dispatch($job);

            return back()->with('notice', 'Leave sync queued. Reload in a few minutes to see the result.');
        }

        $next = new SyncLoasJob($request->user()->id, $full, followUp: true);

        if ($next->isAlreadyQueued()) {
            return back()->with('notice', 'A leave sync is running and another is queued after it. That one will include your request.');
        }

        dispatch($next);

        return back()->with('notice', 'A leave sync is already queued or running. Yours will run right after it.');
    }
}
