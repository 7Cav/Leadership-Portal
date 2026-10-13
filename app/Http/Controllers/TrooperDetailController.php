<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Milpac\TrooperMilpac;
use App\Models\Trooper;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One trooper's milpac (Stories 6.3 to 6.6): the page every entry point to a trooper opens. It orchestrates
 * only; what the page shows comes from {@see TrooperMilpac}.
 *
 * Who may see it is decided by the single ScopeResolver (AD-8, AD-6), the same `canSee` gate the roster Sheet
 * uses: a trooper outside the viewer's effective scope answers 404 exactly like an id that does not exist,
 * whatever link led there, so ids cannot be enumerated. A view-as preview narrows it as it would for that
 * leader (AD-9). A trooper the sync has not placed anywhere belongs to no scope, so only an Admin sees them.
 */
class TrooperDetailController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperMilpac $milpac,
    ) {}

    public function show(Request $request, Trooper $trooper): Response
    {
        abort_unless($this->scope->canSee($request->user(), $trooper), 404);

        return Inertia::render('TrooperDetail', $this->milpac->for($trooper));
    }
}
