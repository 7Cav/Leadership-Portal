<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\SyncRosterJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The manual roster-sync trigger (Story 2.4), posted by the roster screen's "Sync now" control
 * (2.8). No `index` here: this controller only authorizes and dispatches.
 */
class RosterSyncController extends Controller
{
    /**
     * Dispatch `SyncRosterJob` through the identical queued path the scheduler uses (AC #1,
     * #3). Admin only, read from the real `is_admin` flag as the org-structure writes do (AD-6).
     */
    public function store(Request $request): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only an Admin may trigger a manual roster sync.');
        }

        $job = new SyncRosterJob($request->user()->id);

        // Dispatching while a whole-roster sync is queued or running is silently dropped (the job is unique), so say
        // so rather than let the page report a sync that never queued.
        if ($job->isAlreadyQueued()) {
            abort(409, 'A roster sync is already queued or running.');
        }

        dispatch($job);

        return back();
    }
}
