<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Jobs\SyncRosterJob;
use App\Repositories\UnitRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The echelon leader's manual roster sync: refreshes only the troopers in one unit's subtree, through the same
 * queued job the scheduler and the Admin trigger use. Who may ask is {@see ScopeResolver::commands()}.
 */
class UnitRosterSyncController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
    ) {}

    public function store(Request $request, int $unit): RedirectResponse
    {
        $found = $this->units->find($unit);

        // One answer for "no such unit" and "not yours": a leader cannot probe for units outside their scope.
        if ($found === null || ! $this->scope->commands($request->user(), $found)) {
            abort(403, 'Only that unit\'s leader or an Admin may sync it.');
        }

        SyncRosterJob::dispatch($request->user()->id, $found->id);

        return back();
    }
}
