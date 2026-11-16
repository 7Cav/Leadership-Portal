<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\PageAccess;
use App\Http\Controllers\Controller;
use App\Milpac\PlacementHistory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Placement changes: every move between units the roster sync or an Admin wrote over the last days, newest first, so
 * a wrong milpac edit (a trooper moved by mistake, then put back) is caught the day it happens and can be reported to
 * whoever made it. A move undone later, or the one that undid it, is marked. Admin-only like the audit log (AD-6);
 * read-only, so there is no Command. Reads `PlacementHistory`, as the milpac does.
 */
class PlacementChangesController extends Controller
{
    /** How far back the list may reach, in days; the default is the first. */
    public const SPANS = [14, 7, 30];

    public function __construct(
        private readonly PlacementHistory $history,
        private readonly PageAccess $pages,
    ) {}

    public function index(Request $request): Response
    {
        $this->pages->adminSetting($request->user(), 'Only an Admin may review placement changes.')->orAbort();

        $validated = $request->validate(['days' => ['nullable', 'integer', 'in:'.implode(',', self::SPANS)]]);
        $days = (int) ($validated['days'] ?? self::SPANS[0]);

        return Inertia::render('Settings/PlacementChanges', [
            'changes' => $this->history->changesSince(CarbonImmutable::now()->utc()->subDays($days)->startOfDay()),
            'days' => $days,
            'spans' => self::SPANS,
        ]);
    }
}
