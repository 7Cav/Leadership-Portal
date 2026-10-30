<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Kpi\StrengthTrends;
use App\Repositories\UnitRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A unit's strength trends: its headcount and in roll call per Cav week, and its children's headcounts beside it. Read
 * only, and scoped as the roster is (AD-8): a unit outside the effective scope, a view-as preview's included, is a 403.
 */
class StrengthTrendsController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly StrengthTrends $trends,
    ) {}

    public function show(Request $request, int $unit): Response
    {
        $found = $this->scope->resolve($request->user())->contains($unit) ? $this->units->find($unit) : null;
        abort_if($found === null, 403, 'That unit is outside your scope.');

        $range = (string) $request->query('range', '12');
        $weeks = match ($range) {
            '26' => 26,
            '52' => 52,
            'all' => null,
            default => 12,
        };

        return Inertia::render('RosterStrength', [
            'unit' => ['id' => $found->id, 'designation' => $found->callsign ?? $found->name, 'name' => $found->name],
            'range' => $weeks === null ? 'all' : (string) $weeks,
            ...$this->trends->forUnit($found, $weeks),
        ]);
    }
}
