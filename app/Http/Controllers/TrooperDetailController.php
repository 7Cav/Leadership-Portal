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
 * Who may see it is decided here, by the single ScopeResolver (AD-8, AD-6): a trooper placed in a section
 * outside the viewer's effective scope is a 403, whatever link led there, and a view-as preview narrows it
 * as it would for that leader (AD-9). A trooper the sync has not placed anywhere belongs to no scope, so
 * only an Admin sees them, the same people who can place them.
 */
class TrooperDetailController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperMilpac $milpac,
    ) {}

    public function show(Request $request, Trooper $trooper): Response
    {
        $user = $request->user();

        $allowed = $trooper->primary_section_id === null
            ? $this->scope->actsAsAdmin($user)
            : $this->scope->resolve($user)->contains($trooper->primary_section_id);

        abort_unless($allowed, 403, 'That trooper is outside your scope.');

        return Inertia::render('TrooperDetail', $this->milpac->for($trooper));
    }
}
