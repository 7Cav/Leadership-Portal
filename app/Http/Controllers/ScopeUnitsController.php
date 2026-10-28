<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Kpi\Headcount;
use App\Models\Unit;
use App\Repositories\UnitRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shell's unit switcher (#426): the units in the viewer's effective scope (view as and act as included, AD-8), as
 * a tree, for the sidebar's menu to open a page for. Navigation only: it never changes scope, and each page checks the
 * unit again. Read when the menu opens, not on every page, since an Admin's list is the whole regiment.
 */
class ScopeUnitsController extends Controller
{
    /**
     * Every unit in scope in tree order (each after its parent, siblings in id order), with its depth under the top of
     * the scope. A line unit with no troopers is left out, as the roster's picker and Home leave it out (#442).
     */
    public function __invoke(Request $request, ScopeResolver $scope, UnitRepository $units, Headcount $headcount): JsonResponse
    {
        $inScope = $scope->resolve($request->user())->flip();
        $shown = $headcount->withoutEmptyLineUnits($units->tree()->filter(fn (Unit $unit) => $inScope->has($unit->id)));
        $ids = $shown->pluck('id')->flip();

        return response()->json([
            'units' => $shown->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'designation' => $unit->callsign ?? $unit->name,
                'name' => $unit->name,
                'type' => $unit->type->value,
                'line' => $unit->isLine(),
                // Its ancestors that are listed too: 0 for a top of the scope.
                'depth' => collect(explode('/', rtrim($unit->path, '/')))
                    ->slice(0, -1)
                    ->filter(fn (string $id) => $ids->has((int) $id))
                    ->count(),
            ])->values(),
        ]);
    }
}
