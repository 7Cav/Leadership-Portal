<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Jobs\SyncRosterJob;
use App\Repositories\UnitRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The echelon leader's manual roster sync: refreshes only the troopers in one unit's subtree, through the same
 * queued job the scheduler and the Admin trigger use. Who may ask is {@see ScopeResolver::commandsUnit()}.
 *
 * Every unit sync fetches the whole roster from Cav (three requests on one member's API key) however small the
 * unit, so one unit sync is accepted across all units and all leaders per cooldown. That keeps the Cav calls a
 * few an hour however many leaders click, and only a request that passed the leader check spends it.
 */
class UnitRosterSyncController extends Controller
{
    /** Seconds after an accepted unit sync before any unit sync is accepted again. */
    public const COOLDOWN_SECONDS = 300;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
    ) {}

    public function store(Request $request, int $unit): RedirectResponse
    {
        $found = $this->units->find($unit);

        // One answer for "no such unit" and "not yours": a leader cannot probe for units outside their scope.
        if ($found === null || ! $this->scope->commandsUnit($request->user(), $found)) {
            abort(403, 'Only that unit\'s leader or an Admin may sync it.');
        }

        $job = new SyncRosterJob($request->user()->id, $found->id);

        // A pending whole-roster sync already covers this unit, and a pending one for this unit is the same job:
        // dispatching would be dropped silently, so say so rather than let the page report a sync that never queued.
        if ((new SyncRosterJob)->isAlreadyQueued() || $job->isAlreadyQueued()) {
            abort(409, 'A sync that covers this unit is already queued or running.');
        }

        if (! RateLimiter::attempt('unit-roster-sync:cooldown', 1, fn () => true, self::COOLDOWN_SECONDS)) {
            abort(429, 'A unit roster sync ran a moment ago. Try again in a few minutes.', [
                'Retry-After' => RateLimiter::availableIn('unit-roster-sync:cooldown'),
            ]);
        }

        dispatch($job);

        return back();
    }
}
