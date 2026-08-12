<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\SyncRosterJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The manual roster-sync trigger (Story 2.4). No `index` here — the roster view this
 * dispatches from is Story 2.8's; this controller exists only to authorize and dispatch.
 */
class RosterSyncController extends Controller
{
    /**
     * Dispatch `SyncRosterJob` through the identical queued path the scheduler uses (AC #1,
     * #3). The Admin gate matches every other admin-gated write in this codebase (org-structure,
     * staff-grants, audit-log) — roster-derived "leader" scope (AD-7) isn't computed yet.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only an Admin may trigger a manual roster sync.');
        }

        SyncRosterJob::dispatch($request->user()->id);

        return back();
    }
}
