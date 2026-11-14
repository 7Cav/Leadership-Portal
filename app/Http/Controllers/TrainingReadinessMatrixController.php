<?php

namespace App\Http\Controllers;

use App\Authorization\PageAccess;
use App\Authorization\ScopeResolver;
use App\Milpac\ReadinessMatrix;
use App\Repositories\UnitRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The training-readiness matrix for one unit (Story 6.9): who holds which course across it. It orchestrates only;
 * what the page shows comes from {@see ReadinessMatrix}.
 *
 * Which units a viewer may open is decided here by the single ScopeResolver (AD-8, AD-6): a unit outside the
 * viewer's effective scope is a 403 whatever link led there, and a view-as preview narrows it as it would for that
 * leader (AD-9). A unit id that does not exist gets the same 403, as on the report and rollcall screens, so the
 * answer never tells an out-of-scope unit from a missing one. The milpac's quick-action link to a trooper's
 * platoon is only a convenience; this gate is the boundary.
 */
class TrainingReadinessMatrixController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly ReadinessMatrix $matrix,
        private readonly UnitRepository $units,
        private readonly PageAccess $pages,
    ) {}

    public function show(Request $request, int $unit): Response
    {
        $found = $this->pages->readiness($request->user(), $unit)->orAbort();

        return Inertia::render('TrainingReadinessMatrix', $this->matrix->for($found));
    }
}
